// Philippine peso formatter for rates and totals.
const peso = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
})

export const formatMoney = (value) => peso.format(Number(value) || 0)
