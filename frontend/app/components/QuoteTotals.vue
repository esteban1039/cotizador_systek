<script setup lang="ts">
import type { Calculation } from '../../shared/types'
import { money } from '~/utils/format'
defineProps<{ calculation: Calculation | null; busy?: boolean }>()
</script>
<template>
  <div class="totals" :aria-busy="busy">
    <div><span>Valor de las partidas</span><strong>{{ money(calculation?.totals.gross) }}</strong></div>
    <div><span>Descuentos</span><strong>{{ calculation ? `− ${money(calculation.totals.discount)}` : '—' }}</strong></div>
    <div><span>Subtotal</span><strong>{{ money(calculation?.totals.subtotal) }}</strong></div>
    <div><span>IVA</span><strong>{{ money(calculation?.totals.tax) }}</strong></div>
    <div class="grand-total"><span>Total<small>Pesos colombianos · COP</small></span><strong data-testid="quote-total">{{ busy ? 'Calculando…' : money(calculation?.totals.total) }}</strong></div>
    <div v-if="calculation?.vat_withholding?.applied"><span>ReteIVA (15 % sobre IVA)</span><strong>{{ busy ? '—' : `− ${money(calculation?.totals.vat_withholding)}` }}</strong></div>
    <div v-if="calculation?.vat_withholding?.applied" class="grand-total"><span>Total a pagar<small>Pesos colombianos · COP</small></span><strong data-testid="quote-payable">{{ busy ? 'Calculando…' : money(calculation?.totals.payable) }}</strong></div>
  </div>
</template>
