/** The admin menu. Each entry needs a permission; the layout hides what the signed-in person may not use. */
export const ADMIN_NAV = [
  {
    title: 'Overview',
    items: [
      { label: 'Dashboard', to: '/admin/dashboard', permission: 'reports.view' },
      { label: 'Sales reports', to: '/admin/reports', permission: 'reports.view' },
      { label: 'Insights', to: '/admin/insights', permission: 'reports.view' },
    ],
  },
  {
    title: 'Sales',
    items: [
      { label: 'Orders', to: '/admin/orders', permission: 'orders.manage' },
      { label: 'Refunds', to: '/admin/refunds', permission: 'refunds.manage' },
      { label: 'Payments', to: '/admin/payments', permission: 'payments.view' },
      { label: 'Reconciliation', to: '/admin/reconciliation', permission: 'payments.view' },
    ],
  },
  {
    title: 'Catalog',
    items: [
      { label: 'Products', to: '/admin/products', permission: 'products.manage' },
      { label: 'Brands & categories', to: '/admin/catalog', permission: 'categories.manage' },
      { label: 'Compatibility rules', to: '/admin/compatibility', permission: 'compatibility.manage' },
      { label: 'Coupons', to: '/admin/coupons', permission: 'coupons.manage' },
    ],
  },
  {
    title: 'People & security',
    items: [
      { label: 'Users', to: '/admin/users', permission: 'users.manage' },
      { label: 'Audit log', to: '/admin/audit', permission: 'audit.view' },
    ],
  },
]

/** Where "/admin" sends someone: the first page in the menu they are allowed to use. */
export const firstAdminPage = (can) =>
  ADMIN_NAV.flatMap((g) => g.items).find((item) => can(item.permission))?.to ?? null
