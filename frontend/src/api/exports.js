import client from './client'

// Data exports (step 10c, Managers): a CSV of one list for a date range,
// saved by the browser. Every download is recorded on the server
// (data_exported). → the file name it was saved as.
export async function downloadExport(path, dataset, from, to) {
  let res
  try {
    res = await client.get(path, { params: { from, to }, responseType: 'blob' })
  } catch (ex) {
    // An error answer arrives as a Blob too: read its JSON so the message
    // (and request id) reach the dialog like any other API error.
    const data = ex?.response?.data
    if (data instanceof Blob) {
      try { ex.response.data = JSON.parse(await data.text()) } catch { /* keep the status */ }
    }
    throw ex
  }
  const name = `${dataset}-${from}-to-${to}.csv`
  const url = URL.createObjectURL(res.data)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
  return name
}
