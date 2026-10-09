/** The choices in admin filter boxes. The API checks every value again, so a typo here can only produce an error, never bad data. */
export const ORDER_STATUSES = [
  { value: 'PENDING_PAYMENT', label: 'Waiting for payment' },
  { value: 'PAYMENT_PROCESSING', label: 'Payment processing' },
  { value: 'PAID', label: 'Paid' },
  { value: 'PROCESSING', label: 'Being prepared' },
  { value: 'PACKED', label: 'Packed' },
  { value: 'SHIPPED', label: 'Shipped' },
  { value: 'DELIVERED', label: 'Delivered' },
  { value: 'REFUND_REQUESTED', label: 'Refund requested' },
  { value: 'REFUNDED', label: 'Refunded' },
  { value: 'CANCELLED', label: 'Cancelled' },
]

export const PAYMENT_STATUSES = [
  { value: 'PENDING', label: 'Awaiting payment' },
  { value: 'PROCESSING', label: 'Processing' },
  { value: 'PAID', label: 'Paid' },
  { value: 'FAILED', label: 'Failed' },
  { value: 'CANCELLED', label: 'Cancelled' },
  { value: 'REFUNDED', label: 'Refunded' },
]

export const CARRIERS = ['J&T Express', 'Pos Laju', 'DHL eCommerce', 'Ninja Van', 'FedEx', 'Other']
