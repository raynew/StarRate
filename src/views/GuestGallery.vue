<template>
  <!-- Passwort-Dialog (vor der Galerie) -->
  <div v-if="passwordDlg" class="sr-guest-pw__overlay">
    <div class="sr-guest-pw__dialog">

      <!-- Branding -->
      <div class="sr-guest-pw__brand">
        <div class="sr-guest-pw__brand-name">StarRate <span class="sr-guest-pw__brand-version">v{{ appVersion }}</span></div>
        <div class="sr-guest-pw__brand-by">by <a href="https://whitespace.de" target="_blank" rel="noopener noreferrer" class="sr-guest-pw__brand-link">Whitespace</a></div>
      </div>

      <h2 class="sr-guest-pw__title">{{ t('starrate', 'Passwortgeschützte Galerie') }}</h2>
      <p class="sr-guest-pw__hint">{{ t('starrate', 'Bitte gib das Passwort ein, um die Galerie zu öffnen.') }}</p>
      <input
        v-model="password"
        class="sr-guest-pw__input"
        type="password"
        :placeholder="t('starrate', 'Passwort eingeben')"
        autofocus
        @keydown.enter="verifyPassword"
      />
      <span v-if="passwordErr" class="sr-guest-pw__error">{{ passwordErr }}</span>
      <div class="sr-guest-pw__actions">
        <button class="sr-guest-pw__btn" :disabled="!password" @click="verifyPassword">
          {{ t('starrate', 'Bestätigen') }}
        </button>
      </div>
    </div>
  </div>

  <!-- Galerie (echtes StarRate-UI) -->
  <Gallery
    v-else
    :guest-mode="true"
    :guest-label="props.guestName"
    :allow-rate="props.canRate"
    :enable-pick-override="props.allowPick"
    :allow-export="props.allowExport"
    :allow-comment="props.allowComment"
    :comment-api="commentApi"
    :load-images-fn="loadImagesFn"
    :rate-fn="rateFn"
    :batch-rate-fn="batchRateFn"
    :thumbnail-url-fn="thumbnailUrlFn"
    :preview-url-fn="previewUrlFn"
    :allow-download="props.allowDownload"
    :download-url-fn="downloadUrlFn"
    :download-zip-url-fn="downloadZipUrlFn"
  />

  <!-- Nachricht des Fotografen (pro Share). Erscheint beim ersten Öffnen und
       nach jeder Änderung der Nachricht; danach über den Info-Button. -->
  <div
    v-if="!passwordDlg && guestMessage && messageOpen"
    class="sr-guest-msg__overlay"
    data-testid="guest-message-dialog"
    @click.self="dismissMessage"
  >
    <div class="sr-guest-msg__dialog" role="dialog" aria-modal="true" aria-labelledby="sr-guest-msg-title">
      <h2 id="sr-guest-msg-title" class="sr-guest-msg__title">{{ t('starrate', 'Nachricht vom Fotografen') }}</h2>
      <p class="sr-guest-msg__text">{{ guestMessage }}</p>
      <div class="sr-guest-msg__actions">
        <button class="sr-guest-pw__btn" data-testid="guest-message-ok" autofocus @click="dismissMessage">
          {{ t('starrate', 'Verstanden') }}
        </button>
      </div>
    </div>
  </div>

  <button
    v-if="!passwordDlg && guestMessage && !messageOpen"
    class="sr-guest-msg__reopen"
    data-testid="guest-message-reopen"
    :title="t('starrate', 'Nachricht vom Fotografen anzeigen')"
    :aria-label="t('starrate', 'Nachricht vom Fotografen anzeigen')"
    @click="messageOpen = true"
  >i</button>
</template>

<script setup>
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import Gallery from './Gallery.vue'

/* global __APP_VERSION__ */
const appVersion = __APP_VERSION__

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps({
  token:       { type: String,  required: true },
  canRate:     { type: Boolean, default: false },
  allowPick:    { type: Boolean, default: false },
  allowExport:  { type: Boolean, default: false },
  allowComment: { type: Boolean, default: false },
  allowDownload: { type: Boolean, default: false },
  guestName:    { type: String,  default: '' },
})

// ── Passwort-State ────────────────────────────────────────────────────────────

const passwordDlg = ref(false)
const password    = ref('')
const passwordErr = ref('')

// pw_token: persistenter Auth-Nachweis für mobile Browser (überlebt Tab-Wechsel)
const STORAGE_KEY  = `sr_guest_pw_${props.token}`
const storedPwToken = ref(localStorage.getItem(STORAGE_KEY) ?? '')

function pwHeader() {
  return storedPwToken.value ? { 'X-StarRate-Pw-Token': storedPwToken.value } : {}
}

function appendPwToken(url) {
  if (!storedPwToken.value) return url
  const sep = url.includes('?') ? '&' : '?'
  return url + sep + 'pw_token=' + encodeURIComponent(storedPwToken.value)
}

function handle401() {
  // pw_token ungültig (Passwort geändert) → löschen und Dialog zeigen
  localStorage.removeItem(STORAGE_KEY)
  storedPwToken.value = ''
  passwordDlg.value   = true
}

// ── Nachricht vom Fotografen ──────────────────────────────────────────────────
//
// Kommt mit der Bildliste (also erst nach der Passwortprüfung). "Gelesen" wird
// pro Share mit einem Hash der Nachricht gemerkt: ändert der Fotograf den Text,
// erscheint der Dialog erneut.

const guestMessage = ref('')
const messageOpen  = ref(false)
const MSG_KEY      = `sr_guest_msg_${props.token}`

function hashMessage(text) {
  let h = 0
  for (let i = 0; i < text.length; i++) {
    h = (Math.imul(31, h) + text.charCodeAt(i)) | 0
  }
  return String(h)
}

function readSeen() {
  try { return localStorage.getItem(MSG_KEY) } catch { return null }
}

function applyGuestMessage(message) {
  const text = typeof message === 'string' ? message.trim() : ''
  if (text === guestMessage.value) return
  guestMessage.value = text
  messageOpen.value  = !!text && readSeen() !== hashMessage(text)
}

function dismissMessage() {
  messageOpen.value = false
  try { localStorage.setItem(MSG_KEY, hashMessage(guestMessage.value)) } catch { /* privater Modus */ }
}

// ── Gast-API-Callbacks ────────────────────────────────────────────────────────

async function loadImagesFn(path) {
  try {
    const url = generateUrl(`/apps/starrate/api/guest/${props.token}/images`)
    const { data } = await axios.get(url, { params: { path }, headers: pwHeader(), timeout: 15000 })
    if (data && 'guest_message' in data) applyGuestMessage(data.guest_message)
    return data
  } catch (e) {
    if (e?.response?.status === 401) handle401()
    throw e
  }
}

async function rateFn(fileId, payload) {
  if (!props.canRate) return
  const url = generateUrl(`/apps/starrate/api/guest/${props.token}/rate`)
  await axios.post(url, {
    file_id:    fileId,
    guest_name: props.guestName || t('starrate', 'Gast'),
    ...payload,
  }, { headers: pwHeader() })
}

async function batchRateFn(ids, payload) {
  if (!props.canRate) return
  const url = generateUrl(`/apps/starrate/api/guest/${props.token}/rate`)
  await Promise.all(ids.map(id =>
    axios.post(url, {
      file_id:    id,
      guest_name: props.guestName || t('starrate', 'Gast'),
      ...payload,
    }, { headers: pwHeader() })
  ))
}

function thumbnailUrlFn(fileId, sz) {
  const s = sz ?? 280
  const base = generateUrl(`/apps/starrate/api/guest/${props.token}/thumbnail/${fileId}?width=${s}&height=${s}`)
  return appendPwToken(base)
}

function previewUrlFn(fileId) {
  const base = generateUrl(`/apps/starrate/api/guest/${props.token}/preview/${fileId}`)
  return appendPwToken(base)
}

function downloadUrlFn(fileId) {
  // Direkter GET (Browser-Download) → pw_token muss in die URL, nicht in einen Header.
  const base = generateUrl(`/apps/starrate/api/guest/${props.token}/download/${fileId}`)
  return appendPwToken(base)
}

function downloadZipUrlFn(ids) {
  const base = generateUrl(`/apps/starrate/api/guest/${props.token}/download-zip?ids=${ids.join(',')}`)
  return appendPwToken(base)
}

const commentApi = {
  async save(fileId, comment, guestName) {
    const url = generateUrl(`/apps/starrate/api/guest/${props.token}/comment`)
    const { data } = await axios.post(url, {
      file_id:    fileId,
      comment,
      guest_name: guestName || props.guestName || t('starrate', 'Gast'),
    }, { headers: pwHeader() })
    return data
  },
  async load(fileId) {
    const url = generateUrl(`/apps/starrate/api/guest/${props.token}/comment/${fileId}`)
    try {
      const { data } = await axios.get(url, { headers: pwHeader() })
      return data ?? null
    } catch {
      return null
    }
  },
  async remove(fileId) {
    const url = generateUrl(`/apps/starrate/api/guest/${props.token}/comment/${fileId}`)
    await axios.delete(url, { headers: pwHeader() })
  },
}

// ── Passwort verifizieren ─────────────────────────────────────────────────────

async function verifyPassword() {
  passwordErr.value = ''
  try {
    const { data } = await axios.post(
      generateUrl(`/apps/starrate/api/guest/${props.token}/verify`),
      { password: password.value }
    )
    // pw_token in localStorage speichern → überlebt Tab-Wechsel auf Mobile
    if (data.pw_token) {
      storedPwToken.value = data.pw_token
      localStorage.setItem(STORAGE_KEY, data.pw_token)
    }
    passwordDlg.value = false
    password.value    = ''
  } catch {
    passwordErr.value = t('starrate', 'Falsches Passwort')
  }
}
</script>

<style scoped>
.sr-guest-pw__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9000;
}
.sr-guest-pw__dialog {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 12px;
  padding: 2rem;
  width: min(400px, 90vw);
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.sr-guest-pw__brand {
  text-align: center;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid #2a2a3e;
  margin-bottom: 0.25rem;
}

.sr-guest-pw__brand-name {
  font-size: 1.25rem;
  font-weight: 700;
  color: #d4d4e8;
  letter-spacing: 0.04em;
}

.sr-guest-pw__brand-version {
  font-size: 0.75rem;
  font-weight: 400;
  color: #7a7a96;
  letter-spacing: 0.04em;
}

.sr-guest-pw__brand-by {
  font-size: 0.75rem;
  color: #7a7a96;
  margin-top: 2px;
}

.sr-guest-pw__brand-link,
.sr-guest-pw__brand-link:visited,
.sr-guest-pw__brand-link:hover,
.sr-guest-pw__brand-link:active {
  color: #8a8aa8 !important;
  text-decoration: underline !important;
}

.sr-guest-pw__title {
  color: #fff;
  font-size: 1.1rem;
  font-weight: 600;
  margin: 0;
}
.sr-guest-pw__hint {
  color: #a1a1aa;
  font-size: 0.875rem;
  margin: 0;
}
.sr-guest-pw__input {
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #d4d4d8;
  font-size: 0.9rem;
  padding: 0.5rem 0.75rem;
  width: 100%;
  box-sizing: border-box;
}
.sr-guest-pw__input:focus { outline: none; border-color: #e94560; }
.sr-guest-pw__error {
  color: #e94560;
  font-size: 0.8rem;
}
.sr-guest-pw__actions {
  display: flex;
  justify-content: flex-end;
}
.sr-guest-pw__btn {
  background: #e94560;
  border: none;
  border-radius: 6px;
  color: #fff;
  cursor: pointer;
  font-size: 0.9rem;
  padding: 0.5rem 1.25rem;
}
.sr-guest-pw__btn:disabled { opacity: 0.4; cursor: not-allowed; }

.sr-guest-msg__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9000;
}
.sr-guest-msg__dialog {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-left: 4px solid #e94560;
  border-radius: 12px;
  padding: 1.5rem 2rem;
  width: min(480px, 90vw);
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  box-sizing: border-box;
}
.sr-guest-msg__title {
  color: #fff;
  font-size: 1.1rem;
  font-weight: 600;
  margin: 0;
}
.sr-guest-msg__text {
  color: #d4d4d8;
  font-size: 0.95rem;
  line-height: 1.5;
  margin: 0;
  overflow-y: auto;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.sr-guest-msg__actions {
  display: flex;
  justify-content: flex-end;
}
.sr-guest-msg__reopen {
  position: fixed;
  left: 16px;
  bottom: 16px;
  z-index: 8000;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 1px solid #3f3f5a;
  background: #16213e;
  color: #d4d4d8;
  font: italic 600 1rem Georgia, serif;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
}
.sr-guest-msg__reopen:hover { border-color: #e94560; color: #fff; }
</style>
