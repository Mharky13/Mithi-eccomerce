;(function () {
  'use strict'

  var scriptUrl = document.currentScript.src
  var authUrl = new URL('../../Admin/admin_login.php', scriptUrl)
  var dashboardUrl = new URL('../../Admin/admin_dashboard.php', scriptUrl)
  var signedIn = false
  var csrfToken = null
  var pages = ['dashboard', 'sellers', 'users', 'products', 'orders', 'complaints']

  async function request(url, options) {
    var response = await fetch(url, Object.assign({
      credentials: 'same-origin'
    }, options || {}))

    var text = await response.text()
    var body
    try {
      body = JSON.parse(text)
    } catch {
      throw new Error('The server returned an invalid response. Check the PHP endpoint and server logs.')
    }

    if (!response.ok || body.success === false || body.error) {
      throw new Error(body.message || (body.error && body.error.message) || 'The request could not be completed.')
    }
    return body
  }

  async function getCsrfToken() {
    if (csrfToken) return csrfToken
    var url = new URL(authUrl)
    url.searchParams.set('action', 'csrf')
    var result = await request(url)
    csrfToken = result.data.csrf_token
    return csrfToken
  }

  async function postAuth(payload) {
    var token = await getCsrfToken()
    return request(authUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token
      },
      body: JSON.stringify(payload)
    })
  }

  function showPage() {
    var page = window.location.hash.slice(1) || 'dashboard'
    if (!signedIn || page === 'login' || pages.indexOf(page) === -1) {
      page = 'login'
      signedIn = false
    }

    document.getElementById('login-view').hidden = page !== 'login'
    document.getElementById('admin-view').hidden = page === 'login'

    document.querySelectorAll('.admin-page').forEach(function (section) {
      section.hidden = section.id !== page
    })

    document.querySelectorAll('.sidebar-link').forEach(function (link) {
      if (link.getAttribute('href') === '#' + page) link.setAttribute('aria-current', 'page')
      else link.removeAttribute('aria-current')
    })

    var heading = document.querySelector('#' + page + ' .page-title')
    document.title = (heading ? heading.textContent : 'Admin Login') + ' — Tara na, Sis!'
  }

  function showLoginError(message) {
    var alertBox = document.getElementById('login-alert')
    var description = document.getElementById('login-description')
    alertBox.hidden = false
    alertBox.querySelector('.login-alert-text').textContent = message
    document.getElementById('login-help').hidden = false
    description.textContent = 'Enter your administrator email and password to continue.'
  }

  async function checkSession() {
    var url = new URL(authUrl)
    url.searchParams.set('action', 'session')

    try {
      await request(url)
      signedIn = true
      if (!window.location.hash || window.location.hash === '#login') {
        window.location.hash = 'dashboard'
      }
    } catch {
      signedIn = false
      if (window.location.hash !== '#login') window.location.hash = 'login'
    }
    showPage()
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('login-form')
    var username = document.getElementById('username')
    var password = document.getElementById('password')
    var submit = form.querySelector('.login-submit')

    // The existing login backend authenticates using email, not a username.
    var usernameLabel = form.querySelector('label[for="username"]')
    usernameLabel.textContent = 'Admin email'
    username.type = 'email'
    username.autocomplete = 'username'
    username.placeholder = 'Enter admin email'

    form.addEventListener('submit', async function (event) {
      event.preventDefault()
      var identifier = username.value.trim()
      var secret = password.value

      if (!identifier || !secret) {
        showLoginError('Enter your admin email and password.')
        return
      }

      submit.disabled = true
      submit.textContent = 'Signing in…'

      try {
        await postAuth({ action: 'login', identifier: identifier, password: secret })
        signedIn = true
        form.reset()
        document.getElementById('login-alert').hidden = true
        document.getElementById('login-help').hidden = true
        window.location.hash = 'dashboard'
        showPage()
      } catch (error) {
        showLoginError(error.message)
      } finally {
        submit.disabled = false
        submit.textContent = 'Login'
      }
    })

    document.querySelectorAll('a[href="#login"]').forEach(function (link) {
      link.addEventListener('click', async function (event) {
        if (!signedIn) return
        event.preventDefault()
        try {
          await postAuth({ action: 'logout' })
        } catch (error) {
          showLoginError(error.message)
          return
        }
        signedIn = false
        csrfToken = null
        window.location.hash = 'login'
        showPage()
      })
    })

    window.addEventListener('hashchange', showPage)
    checkSession()
  })
})()