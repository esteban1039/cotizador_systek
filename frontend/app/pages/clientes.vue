<script setup lang="ts">
import { errorMessages } from '~/utils/format'
useHead({ title: 'Clientes · JARVIS' })
type Client = {id:string;name:string;nit:string|null;withholds_vat:boolean;sites:{id:string;name:string;city:string;address:string}[];contacts:{id:string;name:string;email:string|null;phone:string|null}[]}
const { user } = useAuth()
const { data, error, refresh } = await useFetch<{data:Client[]}>('/api/backend/clients')
const errors = ref<string[]>([]), success = ref(''), pending = ref(false), selected = ref('')
const client = reactive({name:'',nit:''}), site = reactive({name:'',city:'',address:''}), contact = reactive({name:'',email:'',phone:''})
const current = computed(() => data.value?.data.find(c => c.id === selected.value))
watch(selected, () => { Object.assign(site, {name:'',city:'',address:''}); Object.assign(contact, {name:'',email:'',phone:''}); taxReason.value = '' })
const taxReason = ref('')
async function toggleWithholdsVat() {
  if (!current.value || pending.value) return
  pending.value = true; errors.value = []; success.value = ''
  try {
    await $fetch(`/api/backend/clients/${current.value.id}/tax-profile`, { method: 'PATCH', body: { withholds_vat: !current.value.withholds_vat, reason: taxReason.value } })
    taxReason.value = ''
    success.value = 'Indicador de agente retenedor actualizado.'
    await refresh()
  } catch (e) { errors.value = errorMessages(e) }
  finally { pending.value = false }
}
async function save(kind:'client'|'site'|'contact') {
 if(pending.value) return
 pending.value=true;errors.value=[];success.value=''
 try { const response=await $fetch<{data:{id:string}}>('/api/backend/'+(kind==='client'?'clients':`clients/${selected.value}/${kind==='site'?'sites':'contacts'}`),{method:'POST',body:kind==='client'?{...client,nit:client.nit||null}:kind==='site'?site:{...contact,email:contact.email||null,phone:contact.phone||null}})
 if(kind==='client'){selected.value=response.data.id;client.name='';client.nit=''} else if(kind==='site'){site.name='';site.city='';site.address=''} else {contact.name='';contact.email='';contact.phone=''}
 success.value='Registro guardado.';await refresh()
 }catch(e){errors.value=errorMessages(e)}finally{pending.value=false}
}
</script>
<template><AdminShell title="Clientes y sedes" description="Mantén los datos de cada cliente y sus contactos." :errors="errors" :success="success"><div v-if="error" class="admin-error" role="alert">No pudimos cargar los clientes. <button class="button secondary" @click="refresh()">Reintentar</button></div><div class="admin-grid"><section class="admin-card"><h2>Nuevo cliente</h2><form class="admin-form" @submit.prevent="save('client')"><label>Razón social<input v-model="client.name" required maxlength="255"></label><label>NIT (opcional)<input v-model="client.nit" inputmode="numeric" maxlength="30"></label><button class="button primary" :disabled="pending">Crear cliente</button></form></section><section class="admin-card"><h2>Directorio de clientes</h2><label class="admin-form">Selecciona un cliente<select v-model="selected"><option value="">Seleccionar…</option><option v-for="c in data?.data" :key="c.id" :value="c.id">{{ c.name }} · {{ c.nit || 'Sin NIT' }}</option></select></label><p v-if="!data?.data.length && !error" class="admin-muted">Aún no hay clientes.</p><div v-if="current" class="admin-section admin-list"><h3>{{ current.name }}</h3><div class="admin-record"><span class="draft-badge">{{ current.withholds_vat ? 'Agente retenedor de IVA' : 'No es agente retenedor de IVA' }}</span><template v-if="user?.role === 'admin'"><label>Motivo<textarea v-model="taxReason" minlength="5" maxlength="1000" /></label><button class="button secondary" :disabled="pending || taxReason.trim().length < 5" @click="toggleWithholdsVat">{{ current.withholds_vat ? 'Quitar agente retenedor' : 'Marcar agente retenedor' }}</button></template></div><div v-for="s in current.sites" :key="s.id" class="admin-record"><strong>{{ s.name }}</strong><p class="admin-muted">{{ s.city }} · {{ s.address || 'Sin dirección' }}</p></div><p v-if="!current.sites.length" class="admin-muted">Sin sedes. Agrega una para cotizar.</p><h3>Contactos</h3><p v-for="c in current.contacts" :key="c.id" class="admin-muted">{{ c.name }} · {{ c.email }} {{ c.phone }}</p><p v-if="!current.contacts.length" class="admin-muted">Sin contactos.</p></div></section><section v-if="current" class="admin-card"><h2>Nueva sede · {{ current.name }}</h2><form class="admin-form" @submit.prevent="save('site')"><label>Nombre de sede<input v-model="site.name" required maxlength="255"></label><label>Ciudad<input v-model="site.city" required maxlength="255"></label><label>Dirección (opcional)<input v-model="site.address" maxlength="255"></label><button class="button primary" :disabled="pending">Guardar sede</button></form></section><section v-if="current" class="admin-card"><h2>Nuevo contacto · {{ current.name }}</h2><form class="admin-form" @submit.prevent="save('contact')"><label>Nombre del contacto<input v-model="contact.name" required maxlength="255"></label><label>Correo electrónico<input v-model="contact.email" type="email" :required="!contact.phone" maxlength="255"></label><label>Teléfono<input v-model="contact.phone" type="tel" :required="!contact.email" maxlength="40"></label><p class="admin-muted">Incluye al menos un correo o teléfono.</p><button class="button primary" :disabled="pending">Guardar contacto</button></form></section></div></AdminShell></template>
