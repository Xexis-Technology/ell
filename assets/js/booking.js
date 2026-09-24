// Booking multi-step UI state: idle/loading/success/error (Alpine.js drives steps)
function bookingFlow() {
  return {
    state: 'idle', error: '', quote: null,
    async fetchQuote(url, payload) {
      this.state = 'loading'; this.error = '';
      try {
        const res = await ellPost(url, payload);
        if (res.errors) { this.state = 'error'; this.error = Object.values(res.errors).join(' '); return; }
        this.quote = res; this.state = 'success';
      } catch (e) { this.state = 'error'; this.error = 'Could not calculate price.'; }
    }
  };
}
