<?php

use App\Enums\BuilderSlot as Slot;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\Compatibility\Part;
use App\Services\Compatibility\Rule;
use App\Services\Compatibility\Rules;
use App\Services\Compatibility\RuleSettings;

/** A part in a slot with the given specs. */
function p(Slot $slot, array $specs, int $quantity = 1, string $name = 'Test part'): Part
{
    return new Part($slot, $name, $specs, $quantity);
}

/** Runs one rule with its default settings (or the given overrides) against the parts. */
function run(Rule $rule, array $parts, ?RuleSettings $settings = null): array
{
    return $rule->evaluate(
        BuildContext::of($parts),
        $settings ?? new RuleSettings(true, $rule->defaultSeverity(), $rule->defaultParams()),
    );
}

function messages(array $issues): array
{
    return array_map(fn (CompatibilityIssue $i) => $i->message, $issues);
}

describe('cpu_socket_matches_motherboard', function () {
    it('accepts matching sockets', function () {
        expect(run(new Rules\CpuSocketMatchesMotherboard, [p(Slot::Cpu, ['socket' => 'AM5']), p(Slot::Motherboard, ['socket' => 'AM5'])]))->toBe([]);
    });

    it('rejects different sockets with a clear reason and names both parts', function () {
        $issues = run(new Rules\CpuSocketMatchesMotherboard, [p(Slot::Cpu, ['socket' => 'AM5']), p(Slot::Motherboard, ['socket' => 'AM4'])]);

        expect(messages($issues))->toBe(['CPU socket AM5 is not compatible with motherboard socket AM4.'])
            ->and($issues[0]->severity)->toBe('error')
            ->and($issues[0]->slots)->toBe([Slot::Cpu, Slot::Motherboard])
            ->and($issues[0]->rule)->toBe('cpu_socket_matches_motherboard');
    });

    it('says nothing when a part or the spec is missing', function () {
        $rule = new Rules\CpuSocketMatchesMotherboard;

        expect(run($rule, [p(Slot::Cpu, ['socket' => 'AM5'])]))->toBe([])
            ->and(run($rule, [p(Slot::Cpu, []), p(Slot::Motherboard, ['socket' => 'AM4'])]))->toBe([]);
    });
});

describe('ram_type_matches_motherboard', function () {
    it('checks DDR generation', function () {
        $rule = new Rules\RamTypeMatchesMotherboard;

        expect(run($rule, [p(Slot::Ram, ['ram_type' => 'DDR5']), p(Slot::Motherboard, ['ram_type' => 'DDR5'])]))->toBe([])
            ->and(messages(run($rule, [p(Slot::Ram, ['ram_type' => 'DDR4']), p(Slot::Motherboard, ['ram_type' => 'DDR5'])])))
            ->toBe(['The motherboard supports DDR5 memory, but the selected RAM is DDR4.']);
    });
});

describe('ram_modules_fit_motherboard', function () {
    it('multiplies sticks per kit by the number of kits', function () {
        $rule = new Rules\RamModulesFitMotherboard;
        $board = p(Slot::Motherboard, ['ram_slots' => 4]);

        expect(run($rule, [p(Slot::Ram, ['modules' => 2], 2), $board]))->toBe([])          // 4 sticks in 4 slots
            ->and(messages(run($rule, [p(Slot::Ram, ['modules' => 2], 3), $board])))       // 6 sticks
            ->toBe(['You selected 6 memory modules, but the motherboard only has 4 memory slots.']);
    });
});

describe('ram_capacity_within_motherboard_limit', function () {
    it('allows exactly the maximum and rejects more', function () {
        $rule = new Rules\RamCapacityWithinMotherboardLimit;
        $board = p(Slot::Motherboard, ['max_ram_gb' => 128]);

        expect(run($rule, [p(Slot::Ram, ['capacity_gb' => 64], 2), $board]))->toBe([])
            ->and(messages(run($rule, [p(Slot::Ram, ['capacity_gb' => 64], 3), $board])))
            ->toBe(["192 GB of RAM exceeds the motherboard's maximum of 128 GB."]);
    });
});

describe('motherboard_fits_case', function () {
    it('requires the form factor to be supported by the case', function () {
        $rule = new Rules\MotherboardFitsCase;
        $case = p(Slot::Case_, ['supported_form_factors' => ['Micro ATX', 'Mini ITX']]);

        expect(run($rule, [p(Slot::Motherboard, ['form_factor' => 'Micro ATX']), $case]))->toBe([])
            ->and(messages(run($rule, [p(Slot::Motherboard, ['form_factor' => 'ATX']), $case])))
            ->toBe(['A ATX motherboard does not fit this case (it supports Micro ATX, Mini ITX).']);
    });
});

describe('gpu_fits_case', function () {
    it('matches the brief: 310 mm card in a 300 mm case is rejected, 300 mm exactly is fine', function () {
        $rule = new Rules\GpuFitsCase;
        $case = p(Slot::Case_, ['gpu_max_length_mm' => 300]);

        expect(messages(run($rule, [p(Slot::Gpu, ['length_mm' => 310]), $case])))
            ->toBe(["GPU length 310 mm exceeds the case's maximum supported GPU length of 300 mm."])
            ->and(run($rule, [p(Slot::Gpu, ['length_mm' => 300]), $case]))->toBe([])
            ->and(run($rule, [p(Slot::Gpu, ['length_mm' => 227]), $case]))->toBe([]);
    });
});

describe('cooler_fits_case', function () {
    it('matches the brief: 165 mm cooler in a 160 mm case is rejected', function () {
        $rule = new Rules\CoolerFitsCase;
        $case = p(Slot::Case_, ['cpu_cooler_max_height_mm' => 160]);

        expect(messages(run($rule, [p(Slot::Cooler, ['height_mm' => 165]), $case])))
            ->toBe(["Cooler height 165 mm exceeds the case's maximum CPU cooler height of 160 mm."])
            ->and(run($rule, [p(Slot::Cooler, ['height_mm' => 160]), $case]))->toBe([]);
    });

    it('skips liquid coolers, which have no height', function () {
        expect(run(new Rules\CoolerFitsCase, [p(Slot::Cooler, ['cooler_type' => 'AIO Liquid']), p(Slot::Case_, ['cpu_cooler_max_height_mm' => 50])]))->toBe([]);
    });
});

describe('cooler_supports_cpu_socket', function () {
    it('requires the cpu socket to be in the cooler mounting list', function () {
        $rule = new Rules\CoolerSupportsCpuSocket;
        $cooler = p(Slot::Cooler, ['supported_sockets' => ['AM4', 'LGA1700']]);

        expect(run($rule, [p(Slot::Cpu, ['socket' => 'AM4']), $cooler]))->toBe([])
            ->and(messages(run($rule, [p(Slot::Cpu, ['socket' => 'AM5']), $cooler])))
            ->toBe(['This cooler does not support the AM5 socket (it supports AM4, LGA1700).']);
    });
});

describe('cooler_cools_cpu', function () {
    it('is a warning by default and compares rating with TDP', function () {
        $rule = new Rules\CoolerCoolsCpu;

        $issues = run($rule, [p(Slot::Cpu, ['tdp_watts' => 170]), p(Slot::Cooler, ['tdp_rating_watts' => 150])]);

        expect(messages($issues))->toBe(['The cooler is rated for 150 W but the CPU has a TDP of 170 W.'])
            ->and($issues[0]->severity)->toBe('warning')
            ->and(run($rule, [p(Slot::Cpu, ['tdp_watts' => 170]), p(Slot::Cooler, ['tdp_rating_watts' => 170])]))->toBe([]);
    });
});

describe('power rules (the brief example: CPU 120 W + GPU 220 W + 80 W other = 420 W, recommended 550 W)', function () {
    $build = fn (int $psuWatts) => [
        p(Slot::Cpu, ['tdp_watts' => 120]),
        p(Slot::Gpu, ['tdp_watts' => 220]),
        p(Slot::Psu, ['wattage' => $psuWatts]),
    ];

    it('shows a warning (not an error) for a 450 W PSU: enough to run, but no headroom', function () use ($build) {
        expect(run(new Rules\PsuCoversEstimatedLoad, $build(450)))->toBe([]);

        $warning = run(new Rules\PsuHasHeadroom, $build(450));
        expect(messages($warning))->toBe(['Estimated draw is 420 W. A power supply of at least 550 W is recommended; you selected 450 W.'])
            ->and($warning[0]->severity)->toBe('warning');
    });

    it('is fully compatible with a 550 W or larger PSU', function () use ($build) {
        foreach ([550, 650, 1000] as $watts) {
            expect(run(new Rules\PsuCoversEstimatedLoad, $build($watts)))->toBe([])
                ->and(run(new Rules\PsuHasHeadroom, $build($watts)))->toBe([]);
        }
    });

    it('is an error when the PSU cannot even cover the estimate, and the headroom warning stays quiet', function () use ($build) {
        $error = run(new Rules\PsuCoversEstimatedLoad, $build(400));

        expect(messages($error))->toBe(['The 400 W power supply is below the estimated system draw of 420 W.'])
            ->and($error[0]->severity)->toBe('error')
            ->and(run(new Rules\PsuHasHeadroom, $build(400)))->toBe([]); // no duplicate message
    });

    it('treats exactly the estimate as covered', function () use ($build) {
        expect(run(new Rules\PsuCoversEstimatedLoad, $build(420)))->toBe([]);
    });

    it('uses the configured base allowance and headroom', function () use ($build) {
        $strict = new RuleSettings(true, 'warning', ['base_system_watts' => 80, 'headroom_percent' => 100]);

        // 420 W with +100% headroom = 840 W, rounded up to 850 W.
        expect(messages(run(new Rules\PsuHasHeadroom, $build(800), $strict)))
            ->toBe(['Estimated draw is 420 W. A power supply of at least 850 W is recommended; you selected 800 W.']);

        $bigBase = new RuleSettings(true, 'error', ['base_system_watts' => 200]);
        expect(messages(run(new Rules\PsuCoversEstimatedLoad, $build(450), $bigBase)))
            ->toBe(['The 450 W power supply is below the estimated system draw of 540 W.']);
    });

    it('works without a graphics card (integrated graphics) and with only a GPU', function () {
        $rule = new Rules\PsuCoversEstimatedLoad;

        expect(messages(run($rule, [p(Slot::Cpu, ['tdp_watts' => 65]), p(Slot::Psu, ['wattage' => 100])])))->toBe(['The 100 W power supply is below the estimated system draw of 145 W.'])
            ->and(messages(run($rule, [p(Slot::Gpu, ['tdp_watts' => 300]), p(Slot::Psu, ['wattage' => 350])])))->toBe(['The 350 W power supply is below the estimated system draw of 380 W.']);
    });

    it('cannot estimate without a CPU or GPU, so it stays quiet', function () {
        expect(run(new Rules\PsuCoversEstimatedLoad, [p(Slot::Psu, ['wattage' => 100])]))->toBe([]);
    });
});

describe('psu_meets_gpu_recommendation', function () {
    it('warns when the PSU is below what the GPU maker recommends', function () {
        $rule = new Rules\PsuMeetsGpuRecommendation;
        $gpu = p(Slot::Gpu, ['recommended_psu_watts' => 750]);

        expect(messages(run($rule, [$gpu, p(Slot::Psu, ['wattage' => 650])])))->toBe(['The GPU manufacturer recommends at least 750 W; you selected 650 W.'])
            ->and(run($rule, [$gpu, p(Slot::Psu, ['wattage' => 750])]))->toBe([]);
    });
});

describe('psu_fits_case', function () {
    it('rejects an ATX PSU in an SFX-only case but allows SFX in an ATX case', function () {
        $rule = new Rules\PsuFitsCase;

        expect(messages(run($rule, [p(Slot::Psu, ['form_factor' => 'ATX']), p(Slot::Case_, ['psu_form_factor' => 'SFX'])])))
            ->toBe(['This case only fits SFX power supplies, but the selected power supply is ATX.'])
            ->and(run($rule, [p(Slot::Psu, ['form_factor' => 'SFX']), p(Slot::Case_, ['psu_form_factor' => 'ATX'])]))->toBe([])
            ->and(run($rule, [p(Slot::Psu, ['form_factor' => 'SFX']), p(Slot::Case_, ['psu_form_factor' => 'SFX'])]))->toBe([]);
    });
});

describe('storage_fits_m2_slots', function () {
    it('counts only M.2 drives against the motherboard slots', function () {
        $rule = new Rules\StorageFitsM2Slots;
        $board = p(Slot::Motherboard, ['m2_slots' => 2]);

        expect(run($rule, [p(Slot::Storage, ['form_factor' => 'M.2 2280'], 2), $board]))->toBe([])
            ->and(messages(run($rule, [p(Slot::Storage, ['form_factor' => 'M.2 2280'], 3), $board])))
            ->toBe(['You selected 3 M.2 drives, but the motherboard only has 2 M.2 slots.'])
            // SATA SSDs and hard drives do not use M.2 slots.
            ->and(run($rule, [p(Slot::Storage, ['form_factor' => '2.5"'], 4), $board]))->toBe([]);
    });
});

describe('rules about missing parts', function () {
    it('cpu_needs_graphics_card warns only when there is no iGPU and no graphics card', function () {
        $rule = new Rules\CpuNeedsGraphicsCard;
        $noIgpu = p(Slot::Cpu, ['integrated_graphics' => 'No'], name: 'Ryzen 5 5600');

        expect(messages(run($rule, [$noIgpu])))->toBe(['Ryzen 5 5600 has no integrated graphics, so you need a graphics card to use a display.'])
            ->and(run($rule, [$noIgpu, p(Slot::Gpu, [])]))->toBe([])
            ->and(run($rule, [p(Slot::Cpu, ['integrated_graphics' => 'Yes'])]))->toBe([])
            ->and($rule->usesMissingParts())->toBeTrue();
    });

    it('cpu_cooler_required warns only for CPUs sold without a cooler and no cooler chosen', function () {
        $rule = new Rules\CpuCoolerRequired;
        $boxed = p(Slot::Cpu, ['cooler_included' => 'No'], name: 'Ryzen 7 7800X3D');

        expect(messages(run($rule, [$boxed])))->toBe(['Ryzen 7 7800X3D does not include a cooler. Add a CPU cooler.'])
            ->and(run($rule, [$boxed, p(Slot::Cooler, [])]))->toBe([])
            ->and(run($rule, [p(Slot::Cpu, ['cooler_included' => 'Yes'])]))->toBe([])
            ->and($rule->usesMissingParts())->toBeTrue();
    });
});

it('uses the severity from the settings, not the rule default', function () {
    $asWarning = new RuleSettings(true, 'warning');

    $issues = run(new Rules\GpuFitsCase, [p(Slot::Gpu, ['length_mm' => 400]), p(Slot::Case_, ['gpu_max_length_mm' => 300])], $asWarning);

    expect($issues[0]->severity)->toBe('warning');
});

it('reads decimal specs fine (floats are numbers too)', function () {
    $issues = run(new Rules\GpuFitsCase, [p(Slot::Gpu, ['length_mm' => 300.5]), p(Slot::Case_, ['gpu_max_length_mm' => 300])]);

    expect($issues)->toHaveCount(1);
});
