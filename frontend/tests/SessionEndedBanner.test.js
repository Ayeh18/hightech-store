import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  ensureCsrfCookie: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}))
vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="JSON.stringify(to)"><slot /></a>' },
  useRoute: () => ({ fullPath: '/builder' }),
}))

import SessionEndedBanner from '@/components/layout/SessionEndedBanner.vue'

describe('SessionEndedBanner', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('is invisible during a normal session', () => {
    expect(mount(SessionEndedBanner).find('[role=alert]').exists()).toBe(false)
  })

  it('explains the sign-out, offers to log back in on the same page, and can be dismissed', async () => {
    useAuthStore().sessionEnded = true
    const wrapper = mount(SessionEndedBanner)

    expect(wrapper.text()).toContain('You were signed out')
    expect(wrapper.text()).toContain('still saved in this browser')
    expect(wrapper.find('a').attributes('href')).toContain('/builder')

    await wrapper.find('button').trigger('click')
    expect(wrapper.find('[role=alert]').exists()).toBe(false)
  })
})
