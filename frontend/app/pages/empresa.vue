<script setup lang="ts">
import type { CompanyProfile } from '../../shared/types'
import { errorMessages, dateLabel } from '~/utils/format'
useHead({ title: 'Empresa emisora · JARVIS' })

const missingLabels: Record<string, string> = {
  legal_name: 'Razón social', nit: 'NIT', address: 'Dirección', phone: 'Teléfono',
  email: 'Correo electrónico', signer_name: 'Nombre del firmante', signer_title: 'Cargo del firmante',
  bank_account: 'Datos bancarios',
}

const { data, error, refresh } = await useFetch<{ data: CompanyProfile }>('/api/backend/admin/company')
const profile = computed(() => data.value?.data)

const errors = ref<string[]>([])
const success = ref('')
const pending = ref(false)

const form = reactive({
  legal_name: '', trade_name: '', nit: '', address: '', phone: '', email: '', website: '',
  signer_name: '', signer_title: '', reason: '', emission_requires_authorization: true,
})
function syncForm() {
  const current = profile.value
  Object.assign(form, {
    legal_name: current?.legal_name ?? '', trade_name: current?.trade_name ?? '', nit: current?.nit ?? '',
    address: current?.address ?? '', phone: current?.phone ?? '', email: current?.email ?? '',
    website: current?.website ?? '', signer_name: current?.signer_name ?? '', signer_title: current?.signer_title ?? '',
    reason: '', emission_requires_authorization: current?.emission_requires_authorization ?? true,
  })
}
watch(profile, syncForm, { immediate: true })

async function savePublicData() {
  if (pending.value) return
  pending.value = true
  errors.value = []
  success.value = ''
  try {
    await $fetch('/api/backend/admin/company', {
      method: 'POST',
      body: {
        legal_name: form.legal_name, trade_name: form.trade_name || null, nit: form.nit,
        address: form.address || null, phone: form.phone || null, email: form.email || null,
        website: form.website || null, signer_name: form.signer_name || null, signer_title: form.signer_title || null,
        emission_requires_authorization: form.emission_requires_authorization,
        reason: form.reason,
      },
    })
    success.value = 'Se publicó una nueva versión de la empresa emisora.'
    form.reason = ''
    await refresh()
  } catch (e) { errors.value = errorMessages(e) }
  finally { pending.value = false }
}

// Cuenta bancaria: de solo escritura. Nunca se guarda en localStorage ni en consola.
const bankOpen = ref(false)
const bank = reactive({ bank_name: '', account_type: 'savings', account_number: '', account_number_confirmation: '', account_holder: '' })
function resetBank() { Object.assign(bank, { bank_name: '', account_type: 'savings', account_number: '', account_number_confirmation: '', account_holder: '' }) }
function openBank() { resetBank(); bankOpen.value = true }

async function saveBankAccount() {
  if (pending.value) return
  pending.value = true
  errors.value = []
  success.value = ''
  try {
    await $fetch('/api/backend/admin/company', {
      method: 'POST',
      body: {
        legal_name: form.legal_name, trade_name: form.trade_name || null, nit: form.nit,
        address: form.address || null, phone: form.phone || null, email: form.email || null,
        website: form.website || null, signer_name: form.signer_name || null, signer_title: form.signer_title || null,
        bank_account: {
          bank_name: bank.bank_name, account_type: bank.account_type,
          account_number: bank.account_number, account_number_confirmation: bank.account_number_confirmation,
          account_holder: bank.account_holder || null,
        },
        reason: form.reason || 'Actualización de datos bancarios',
      },
    })
    success.value = 'Se actualizó la cuenta bancaria.'
    resetBank()
    bankOpen.value = false
    form.reason = ''
    await refresh()
  } catch (e) { errors.value = errorMessages(e) }
  finally { pending.value = false }
}
async function clearBankAccount() {
  if (pending.value || !window.confirm('¿Quitar la cuenta bancaria configurada?')) return
  pending.value = true
  errors.value = []
  success.value = ''
  try {
    await $fetch('/api/backend/admin/company', {
      method: 'POST',
      body: {
        legal_name: form.legal_name, trade_name: form.trade_name || null, nit: form.nit,
        address: form.address || null, phone: form.phone || null, email: form.email || null,
        website: form.website || null, signer_name: form.signer_name || null, signer_title: form.signer_title || null,
        clear_bank_account: true,
        reason: form.reason || 'Eliminación de datos bancarios',
      },
    })
    success.value = 'Se quitó la cuenta bancaria.'
    form.reason = ''
    await refresh()
  } catch (e) { errors.value = errorMessages(e) }
  finally { pending.value = false }
}
</script>
<template>
  <AdminShell title="Empresa emisora" description="Datos oficiales de Systek para el membrete de los borradores. No habilita emisión." :errors="errors" :success="success">
    <div v-if="error" class="admin-error" role="alert">No pudimos cargar la empresa emisora. <button class="button secondary" @click="refresh()">Reintentar</button></div>
    <template v-else-if="profile">
      <div v-if="!profile.complete" class="notice" role="status">
        <strong>Configuración incompleta.</strong>
        <p>Faltan: {{ profile.missing.map(field => missingLabels[field] || field).join(', ') }}. Esto bloquea la emisión futura, no los borradores.</p>
      </div>
      <div class="admin-grid">
        <section class="admin-card">
          <h2>Datos públicos <span v-if="profile.origin === 'initial_load'" class="draft-badge">Carga inicial</span></h2>
          <form class="admin-form" @submit.prevent="savePublicData">
            <label>Razón social<input v-model="form.legal_name" required maxlength="200"></label>
            <label>Nombre comercial (opcional)<input v-model="form.trade_name" maxlength="100"></label>
            <label>NIT<input v-model="form.nit" required maxlength="20" placeholder="901704107-1"></label>
            <label>Dirección<input v-model="form.address" maxlength="255"></label>
            <label>Teléfono<input v-model="form.phone" maxlength="40"></label>
            <label>Correo electrónico<input v-model="form.email" type="email" maxlength="255"></label>
            <label>Sitio web (https)<input v-model="form.website" type="url" maxlength="255" placeholder="https://…"></label>
            <label>Nombre del firmante<input v-model="form.signer_name" maxlength="150"></label>
            <label>Cargo del firmante<input v-model="form.signer_title" maxlength="150"></label>
            <label class="admin-check"><input v-model="form.emission_requires_authorization" type="checkbox" role="switch"> Exigir autorización adicional al emitir</label>
            <p class="admin-muted">La aprobación interna siempre es obligatoria. Activado: solo administradores y aprobadores emiten, y nunca el autor de la cotización. Desactivado: emiten el administrador y el cotizador dueño.</p>
            <label>Motivo<textarea v-model="form.reason" required minlength="5" maxlength="1000" /></label>
            <p class="admin-muted">Publicar crea una versión nueva; las anteriores quedan como histórico.</p>
            <button class="button primary" :disabled="pending">Publicar versión</button>
          </form>
        </section>
        <section class="admin-card">
          <h2>Versión vigente</h2>
          <p v-if="!profile.configured" class="admin-muted">Aún no hay ninguna versión publicada.</p>
          <template v-else>
            <p class="admin-muted">Versión {{ profile.version }} · Actualizada {{ profile.updated_at ? dateLabel(profile.updated_at) : '—' }} por {{ profile.updated_by_name || 'Sistema' }}</p>
            <div class="admin-list admin-section">
              <article class="admin-record"><strong>{{ profile.legal_name }}</strong><p class="admin-muted">{{ profile.trade_name || 'Sin nombre comercial' }} · NIT {{ profile.nit || '—' }}</p></article>
              <article class="admin-record"><p class="admin-muted">{{ profile.address || 'Sin dirección' }}</p><p class="admin-muted">{{ profile.phone || 'Sin teléfono' }} · {{ profile.email || 'Sin correo' }}</p><p class="admin-muted">{{ profile.website || 'Sin sitio web' }}</p></article>
            </div>
          </template>
          <div class="admin-section">
            <h3>Datos bancarios</h3>
            <p class="admin-muted">Solo escritura: nunca se muestra el número completo ni el titular.</p>
            <p v-if="profile.bank_account_configured && profile.bank_account_summary">
              {{ profile.bank_account_summary.bank_name }} · {{ profile.bank_account_summary.account_type === 'savings' ? 'Ahorros' : 'Corriente' }} · {{ profile.bank_account_summary.account_number_masked }}
            </p>
            <p v-else class="admin-muted">Sin configurar.</p>
            <div class="admin-actions" style="margin-top:12px">
              <button type="button" class="button secondary" :disabled="pending" @click="openBank">Reemplazar</button>
              <button v-if="profile.bank_account_configured" type="button" class="button secondary" :disabled="pending" @click="clearBankAccount">Quitar</button>
            </div>
            <form v-if="bankOpen" class="admin-form admin-section" autocomplete="off" @submit.prevent="saveBankAccount">
              <label>Banco<input v-model="bank.bank_name" required minlength="2" maxlength="100" autocomplete="off"></label>
              <label>Tipo de cuenta<select v-model="bank.account_type" required><option value="savings">Ahorros</option><option value="checking">Corriente</option></select></label>
              <label>Número de cuenta<input v-model="bank.account_number" required autocomplete="off" inputmode="numeric"></label>
              <label>Confirmar número de cuenta<input v-model="bank.account_number_confirmation" required autocomplete="off" inputmode="numeric"></label>
              <label>Titular (opcional)<input v-model="bank.account_holder" maxlength="200" autocomplete="off"></label>
              <label>Motivo del cambio bancario<textarea v-model="form.reason" required minlength="5" maxlength="1000" /></label>
              <div class="admin-actions">
                <button class="button primary" :disabled="pending">Guardar cuenta bancaria</button>
                <button type="button" class="button secondary" :disabled="pending" @click="bankOpen = false; resetBank()">Cancelar</button>
              </div>
            </form>
          </div>
        </section>
      </div>
    </template>
  </AdminShell>
</template>
