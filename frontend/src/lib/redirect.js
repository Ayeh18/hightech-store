/**
 * Sends the whole browser to another address (a full page load, not a Vue route). Used to go to a payment company's
 * page and to come back from it. It lives in its own file so tests can replace it: jsdom cannot navigate.
 */
export const redirectTo = (url) => window.location.assign(url)
