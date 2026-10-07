import axios from 'axios'

/**
 * One shared Axios instance for every API call.
 * - withCredentials: send the session cookie (Sanctum SPA authentication).
 * - withXSRFToken: copy Laravel's XSRF-TOKEN cookie into the X-XSRF-TOKEN header automatically.
 */
export const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

/** Ask Laravel to set the XSRF cookie. Must run before the first POST/PUT/DELETE. */
export const ensureCsrfCookie = () => api.get('/sanctum/csrf-cookie')

let onUnauthorized = () => {}
/** The auth store registers a callback here so this file never imports a store (avoids a cycle). */
export const setUnauthorizedHandler = (fn) => {
  onUnauthorized = fn
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const { response, config } = error

    // 419 = CSRF token expired. Refresh the cookie and retry the request once.
    if (response?.status === 419 && config && !config._retried) {
      config._retried = true
      await ensureCsrfCookie()
      return api(config)
    }

    // 401 on a normal call = the session ended. (Failed logins come back as 422, not 401.)
    if (response?.status === 401 && !config?.url?.includes('/auth/me')) {
      onUnauthorized()
    }

    return Promise.reject(error)
  },
)

/**
 * Turn any Axios error into { message, errors } so components never poke at raw responses.
 * Matches the API's error shape: { "message": "...", "errors": { field: ["..."] } }.
 */
export function parseApiError(error) {
  const data = error?.response?.data
  if (data?.message || data?.errors) {
    return { message: data.message || 'Something went wrong.', errors: data.errors || {} }
  }
  if (error?.response?.status >= 500) {
    return { message: 'The server had a problem. Please try again shortly.', errors: {} }
  }
  return { message: 'Unable to reach the server. Check your connection and try again.', errors: {} }
}
