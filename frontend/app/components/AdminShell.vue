<script setup lang="ts">
import '~/assets/css/admin.css'
defineProps<{ title: string; description: string; errors?: string[]; success?: string }>()
</script>
<template><section class="admin-page"><header class="page-heading"><div><p class="eyebrow">ADMINISTRACIÓN · JARVIS</p><h1>{{ title }}</h1><p>{{ description }}</p></div></header><div v-if="errors?.length" class="admin-error" role="alert"><p v-for="message in errors" :key="message">{{ message }}</p></div><p v-if="success" class="admin-success" role="status">{{ success }}</p><slot /></section></template>
