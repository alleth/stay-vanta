import client from './client'

// Platform-owner reports: registered hotels/resorts + subscription status.
export const getOverview = () =>
  client.get('/reports/overview').then((r) => r.data.overview)

// Flip a hotel/resort's subscription (and other property fields). Owner only.
export const updateProperty = (id, data) =>
  client.patch(`/properties/${id}`, data).then((r) => r.data.property)
