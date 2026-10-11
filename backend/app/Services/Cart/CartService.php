<?php

namespace App\Services\Cart;

use App\Enums\CartItemKind;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\PcBuilder\BuildIssue;
use App\Services\PcBuilder\EvaluatedBuild;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes to a customer's cart. Every rule is enforced here on the server: the browser only asks.
 * (Stock is NOT reserved by the cart. It is reserved at checkout, under a row lock.)
 */
class CartService
{
    public const MAX_PRODUCT_QUANTITY = 10;

    public const MAX_BUILD_QUANTITY = 5;

    public const MAX_LINES = 30;

    public function __construct(private readonly CartEvaluator $evaluator) {}

    /** The customer's cart, created on first use. */
    public function cartFor(User $user): Cart
    {
        $existing = Cart::where('user_id', $user->id)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return Cart::create(['user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            return Cart::where('user_id', $user->id)->firstOrFail(); // two requests raced; the other one won
        }
    }

    public function addProduct(User $user, int $productId, int $quantity): CartItem
    {
        $cart = $this->cartFor($user);

        return DB::transaction(function () use ($cart, $productId, $quantity) {
            $this->lock($cart);

            $product = Product::visible()->with('inventory')->find($productId);

            if ($product === null) {
                throw ValidationException::withMessages(['product_id' => 'This product is not available.']);
            }

            $existing = $cart->items()->where('kind', CartItemKind::Product->value)->where('product_id', $product->id)->first();
            $newQuantity = ($existing->quantity ?? 0) + $quantity;

            if ($existing === null) {
                $this->ensureRoomForAnotherLine($cart);
            }

            $this->ensureQuantityAllowed($newQuantity, self::MAX_PRODUCT_QUANTITY, $product->name);
            $this->ensureStock($cart, $product, $newQuantity - ($existing->quantity ?? 0));

            // The customer has just seen this product's current price, so that is the price to compare against later.
            $attributes = ['quantity' => $newQuantity, 'price_when_added' => $product->effective_price];

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            return $cart->items()->create([...$attributes, 'kind' => CartItemKind::Product, 'product_id' => $product->id]);
        });
    }

    /**
     * Adds a whole PC. The build must be complete, in stock and free of compatibility errors, judged by the server
     * right now. The parts and their prices are copied into the cart, so the line keeps meaning what it meant.
     */
    public function addBuild(User $user, EvaluatedBuild $evaluated, string $name, ?string $sourceBuildId, int $quantity): CartItem
    {
        if (! $evaluated->isPurchasable()) {
            throw ValidationException::withMessages(['build' => $this->blockers($evaluated)]);
        }

        $cart = $this->cartFor($user);

        return DB::transaction(function () use ($cart, $evaluated, $name, $sourceBuildId, $quantity) {
            $this->lock($cart);
            $this->ensureRoomForAnotherLine($cart);
            $this->ensureQuantityAllowed($quantity, self::MAX_BUILD_QUANTITY, $name);

            foreach ($evaluated->priced->lines as $line) {
                $this->ensureStock($cart, $line->product, $quantity * $line->quantity);
            }

            $item = $cart->items()->create([
                'kind' => CartItemKind::Build,
                'pc_build_id' => $sourceBuildId,
                'name' => $name,
                'quantity' => $quantity,
                'price_when_added' => $evaluated->priced->subtotal,
            ]);

            foreach ($evaluated->priced->lines as $line) {
                $item->components()->create([
                    'product_id' => $line->product->id,
                    'slot' => $line->slot,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ]);
            }

            return $item;
        });
    }

    public function updateQuantity(CartItem $item, int $quantity): CartItem
    {
        return DB::transaction(function () use ($item, $quantity) {
            $cart = $item->cart;
            $this->lock($cart);
            $item = CartItem::with(['product.inventory', 'components.product.inventory'])->findOrFail($item->id);

            if ($item->isBuild()) {
                $this->ensureQuantityAllowed($quantity, self::MAX_BUILD_QUANTITY, $item->name ?? 'This PC');

                foreach ($item->components as $component) {
                    $this->ensureStock($cart, $component->product, ($quantity - $item->quantity) * $component->quantity);
                }
            } else {
                $this->ensureQuantityAllowed($quantity, self::MAX_PRODUCT_QUANTITY, $item->product->name);
                $this->ensureStock($cart, $item->product, $quantity - $item->quantity);
            }

            $item->update(['quantity' => $quantity]);

            return $item;
        });
    }

    public function remove(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(User $user): void
    {
        $this->cartFor($user)->items()->delete();
    }

    /** "Accept the new prices": from now on the customer is warned only about changes after this moment. */
    public function acceptPrices(User $user): void
    {
        $cart = $this->cartFor($user);

        DB::transaction(function () use ($cart) {
            $this->lock($cart);

            foreach ($this->evaluator->evaluate($cart)->lines as $line) {
                if ($line->isPriced()) {
                    $line->item->update(['price_when_added' => $line->unitPrice]);
                }
            }
        });
    }

    /** Serialises changes to one customer's cart, so two quick clicks cannot both pass the same stock check. */
    private function lock(Cart $cart): void
    {
        Cart::whereKey($cart->id)->lockForUpdate()->first();
    }

    private function ensureRoomForAnotherLine(Cart $cart): void
    {
        if ($cart->items()->count() >= self::MAX_LINES) {
            throw ValidationException::withMessages(['cart' => 'Your cart is full ('.self::MAX_LINES.' items). Remove something before adding more.']);
        }
    }

    private function ensureQuantityAllowed(int $quantity, int $max, string $name): void
    {
        if ($quantity < 1 || $quantity > $max) {
            throw ValidationException::withMessages(['quantity' => "You can order between 1 and {$max} of {$name}."]);
        }
    }

    /**
     * Checks that `$extraUnits` more of this product can be taken from stock, counting everything already in the
     * cart: single product lines and the parts inside builds.
     */
    private function ensureStock(Cart $cart, Product $product, int $extraUnits): void
    {
        if ($extraUnits <= 0) {
            return;
        }

        $available = Inventory::where('product_id', $product->id)->first()->available ?? 0;
        $inCart = $this->unitsInCart($cart, $product->id);

        if ($inCart + $extraUnits <= $available) {
            return;
        }

        $room = max(0, $available - $inCart);
        $message = match (true) {
            $available <= 0 => "{$product->name} is out of stock.",
            $room === 0 => "You already have all {$available} available of {$product->name} in your cart.",
            default => "Only {$room} more of {$product->name} can be added (".$available.' in stock).',
        };

        throw ValidationException::withMessages(['quantity' => $message]);
    }

    private function unitsInCart(Cart $cart, int $productId): int
    {
        $single = (int) DB::table('cart_items')
            ->where('cart_id', $cart->id)->where('kind', CartItemKind::Product->value)->where('product_id', $productId)
            ->sum('quantity');

        $inBuilds = (int) DB::table('cart_item_components AS c')
            ->join('cart_items AS i', 'i.id', '=', 'c.cart_item_id')
            ->where('i.cart_id', $cart->id)->where('c.product_id', $productId)
            ->sum(DB::raw('i.quantity * c.quantity'));

        return $single + $inBuilds;
    }

    /**
     * Why a build cannot go in the cart, in plain words: missing parts, stock/availability errors, then
     * compatibility errors.
     *
     * @return array<int, string>
     */
    private function blockers(EvaluatedBuild $evaluated): array
    {
        $reasons = [];

        foreach ($evaluated->priced->missingSlots as $slot) {
            $reasons[] = "Choose a {$slot->label()} to complete this build.";
        }

        foreach ($evaluated->priced->issues as $issue) {
            if ($issue->severity === BuildIssue::ERROR) {
                $reasons[] = $issue->message;
            }
        }

        foreach ($evaluated->compatibility->issues as $issue) {
            if ($issue->severity === 'error') {
                $reasons[] = $issue->message;
            }
        }

        return $reasons ?: ['This build cannot be bought right now.'];
    }
}
