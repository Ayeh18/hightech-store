<template>
  <!-- A payment company's page looks nothing like the shop: no shop header, no cart. This is its bare frame. -->
  <div class="flex min-h-screen items-center justify-center bg-slate-200 px-4 py-10 text-slate-900">
    <main class="w-full max-w-md"><slot /></main>
  </div>
</template>
