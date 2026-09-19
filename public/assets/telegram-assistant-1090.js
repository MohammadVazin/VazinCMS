(() => {
  'use strict';

  const stateNode = document.getElementById('telegram-assistant-state');
  if (!stateNode) return;

  let initial;
  try { initial = JSON.parse(stateNode.textContent || '{}'); }
  catch (_) { return; }

  const root = document.documentElement;
  const app = document.getElementById('telegram-assistant-app');
  const tg = window.Telegram?.WebApp || null;
  const q = (selector, scope = document) => scope.querySelector(selector);
  const qa = (selector, scope = document) => [...scope.querySelectorAll(selector)];
  const asArray = value => Array.isArray(value) ? value : (Array.isArray(value?.items) ? value.items : []);
  const copy = initial.copy || {};
  const text = (key, values = {}) => {
    let value = String(copy[key] || key);
    Object.entries(values).forEach(([name, replacement]) => { value = value.replaceAll(`{${name}}`, String(replacement)); });
    return value;
  };
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const cleanObject = value => Object.fromEntries(Object.entries(value).filter(([, item]) => item !== '' && item !== null && item !== undefined));
  const enabledFlag = value => value === true || value === 1 || value === '1';
  const identifier = prefix => `${prefix}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;

  const state = {
    mode: initial.mode === 'admin' ? 'admin' : 'customer',
    key: String(initial.key || ''),
    locale: ['ru', 'en', 'fa'].includes(initial.locale) ? initial.locale : 'fa',
    csrf: String(initial.csrf || ''),
    sessionToken: '',
    preview: Boolean(initial.preview),
    profile: initial.profile && typeof initial.profile === 'object' ? initial.profile : {},
    services: asArray(initial.services),
    slots: asArray(initial.slots),
    bookings: asArray(initial.bookings),
    conversations: asArray(initial.conversations),
    businessConnections: asArray(initial.business_connections),
    adminSummary: {counts:{}, notices:[]},
    availabilityRules: [],
    availabilityServiceId: '',
    miniAppUrl: String(initial.mini_app_url || ''),
    currentView: 'home',
    currentAdminView: 'inbox',
    conversation: null,
    booking: {step: 1, service: null, date: '', slot: null, details: {}, hold: null},
  };

  const apiBase = state.key ? `/telegram/assistant/${encodeURIComponent(state.key)}/api` : '';
  const endpoints = () => state.profile?.endpoints || {};
  const endpoint = (name, fallback) => String(endpoints()[name] || `${apiBase}${fallback}`);
  let toastTimer;

  const loading = (active, message = text('loading')) => {
    const node = q('[data-loading]');
    if (!node) return;
    q('[data-loading-text]', node).textContent = message;
    node.hidden = !active;
  };

  const toast = (message, timeout = 3300) => {
    const node = q('[data-toast]');
    if (!node || !message) return;
    clearTimeout(toastTimer);
    node.textContent = String(message);
    node.hidden = false;
    toastTimer = setTimeout(() => { node.hidden = true; }, timeout);
    tg?.HapticFeedback?.notificationOccurred?.('success');
  };

  const errorMessage = error => {
    if (!navigator.onLine) return text('offline');
    const code = String(error?.code || '');
    if (['slot_taken','slot_unavailable','slot_not_available'].includes(code)) return text('slot_taken');
    if (['session_expired','unauthorized','invalid_init_data','invalid_session'].includes(code)) return text('session_expired');
    return String(error?.message || text('unexpected_error'));
  };

  const request = async (path, options = {}) => {
    if (state.preview && (options.method || 'GET').toUpperCase() !== 'GET') {
      const error = new Error(text('preview_notice')); error.code = 'read_only'; throw error;
    }
    if (!path) throw new Error(text('unexpected_error'));
    let resolved;
    try { resolved = new URL(path, window.location.origin); }
    catch (_) { throw new Error(text('unexpected_error')); }
    if (resolved.origin !== window.location.origin) throw new Error(text('unexpected_error'));
    const method = (options.method || 'GET').toUpperCase();
    const headers = {'Accept':'application/json', ...(options.headers || {})};
    const useCsrf = state.mode === 'admin' && options.csrf !== false;
    if (state.mode === 'customer' && state.sessionToken && options.auth !== false) {
      headers.Authorization = `Bearer ${state.sessionToken}`;
    }
    const fetchOptions = {method, headers, credentials:state.mode === 'admin' ? 'same-origin' : 'omit'};
    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      if (useCsrf) headers['X-CSRF-Token'] = state.csrf;
      const body = useCsrf && typeof options.body === 'object' && options.body !== null
        ? {...options.body, _csrf: state.csrf}
        : options.body;
      fetchOptions.body = typeof body === 'string' ? body : JSON.stringify(body);
    }
    if (options.idempotency) headers['Idempotency-Key'] = options.idempotency;
    const response = await fetch(path, fetchOptions);
    let payload = {};
    try { payload = await response.json(); } catch (_) {}
    if (!response.ok || payload.ok === false) {
      if (state.mode === 'customer' && response.status === 401) state.sessionToken = '';
      const detail = payload.error && typeof payload.error === 'object' ? payload.error : {};
      const error = new Error(detail.message || payload.message || `HTTP ${response.status}`);
      error.code = detail.code || payload.code || `http_${response.status}`;
      error.fields = detail.fields || payload.fields || {};
      error.status = response.status;
      throw error;
    }
    return payload.data !== undefined ? payload.data : payload;
  };

  const formatPrice = service => {
    if (service?.price?.display) return String(service.price.display);
    const priceValue = service?.price;
    const amount = priceValue && typeof priceValue === 'object'
      ? priceValue.amount
      : (service?.price_amount ?? priceValue);
    if (amount === '' || amount === null || amount === undefined) return text('price_on_request');
    const numeric = Number(amount);
    if (!Number.isFinite(numeric)) return String(amount);
    const currency = String(service?.price?.currency || service?.currency || 'RUB').toUpperCase();
    try { return new Intl.NumberFormat(state.locale === 'fa' ? 'fa-IR' : state.locale, {style:'currency', currency, maximumFractionDigits:2}).format(numeric); }
    catch (_) { return `${numeric.toLocaleString()} ${currency}`; }
  };

  const modeLabel = mode => ({online:text('online'), offline:text('offline_mode'), in_person:text('offline_mode'), hybrid:text('hybrid'), both:text('hybrid')})[String(mode)] || '';
  const serviceTitle = service => String(service?.title || service?.name || service?.slug || '');
  const serviceId = service => String(service?.id ?? service?.service_id ?? service?.slug ?? '');
  const slotId = slot => String(slot?.id ?? slot?.slot_id ?? '');
  const bookingReference = booking => String(booking?.reference || booking?.public_ref || booking?.public_id || booking?.id || '');
  const statusLabel = status => text(`status_${String(status || 'requested').toLowerCase()}`);
  const reservationDisplayStatus = booking => String(booking?.resolution === 'rejected' ? 'rejected' : (booking?.status || 'requested')).toLowerCase();
  const slotStatusLabel = status => {
    const normalized = String(status || 'available').toLowerCase();
    const key = `slot_status_${normalized}`;
    const label = text(key);
    return label === key ? normalized : label;
  };
  const capabilityEnabled = name => {
    const capabilities = state.profile?.capabilities;
    if (!capabilities || !Object.prototype.hasOwnProperty.call(capabilities, name)) return true;
    return enabledFlag(capabilities[name]);
  };
  const syncCapabilities = () => {
    if (state.mode !== 'customer') return;
    qa('[data-capability]').forEach(node => {
      const enabled = capabilityEnabled(node.dataset.capability);
      node.hidden = !enabled;
      if ('disabled' in node) node.disabled = !enabled;
      enabled ? node.removeAttribute('aria-disabled') : node.setAttribute('aria-disabled','true');
    });
    q('.ta-bottom-nav')?.classList.toggle('is-booking-disabled', !capabilityEnabled('booking'));
    if (!capabilityEnabled('booking') && ['booking','bookings'].includes(state.currentView)) navigate('home');
  };

  const datePartsInZone = (date, timeZone) => {
    const formatter = new Intl.DateTimeFormat('en-CA', {timeZone, year:'numeric', month:'2-digit', day:'2-digit'});
    const parts = Object.fromEntries(formatter.formatToParts(date).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
  };

  const businessTimezone = () => {
    const candidate = String(state.profile?.business?.timezone || state.profile?.timezone || initial.slots?.timezone || 'Asia/Novosibirsk');
    try { new Intl.DateTimeFormat('en', {timeZone:candidate}).format(); return candidate; }
    catch (_) { return 'UTC'; }
  };
  const dateLabel = value => {
    if (!value) return '';
    const date = new Date(/T/.test(value) ? value : `${value}T12:00:00Z`);
    if (Number.isNaN(date.getTime())) return String(value);
    try { return new Intl.DateTimeFormat(state.locale === 'fa' ? 'fa-IR' : state.locale, {weekday:'short', day:'numeric', month:'short', timeZone:businessTimezone()}).format(date); }
    catch (_) { return String(value); }
  };
  const dateTimeLabel = (value, end = '') => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    const options = {day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', timeZone:businessTimezone()};
    let label;
    try { label = new Intl.DateTimeFormat(state.locale === 'fa' ? 'fa-IR' : state.locale, options).format(date); }
    catch (_) { label = String(value); }
    if (end) {
      const endDate = new Date(end);
      if (!Number.isNaN(endDate.getTime())) {
        try { label += ` – ${new Intl.DateTimeFormat(state.locale === 'fa' ? 'fa-IR' : state.locale, {hour:'2-digit',minute:'2-digit',timeZone:businessTimezone()}).format(endDate)}`; }
        catch (_) {}
      }
    }
    return label;
  };
  const slotTimeLabel = slot => String(slot?.local_time || '') || (() => {
    const date = new Date(slot?.start_at || '');
    if (Number.isNaN(date.getTime())) return '';
    try { return new Intl.DateTimeFormat(state.locale === 'fa' ? 'fa-IR' : state.locale, {hour:'2-digit', minute:'2-digit', timeZone:businessTimezone()}).format(date); }
    catch (_) { return ''; }
  })();

  const setTelegramBack = visible => {
    if (!tg?.BackButton) return;
    visible ? tg.BackButton.show() : tg.BackButton.hide();
  };
  const hideTelegramMain = () => tg?.MainButton?.hide?.();

  const navigate = view => {
    if (state.mode !== 'customer') return;
    if (!capabilityEnabled('booking') && ['booking','bookings'].includes(view)) view = 'home';
    state.currentView = ['home','services','booking','bookings'].includes(view) ? view : 'home';
    qa('[data-view]').forEach(node => { const active = node.dataset.view === state.currentView; node.hidden = !active; node.classList.toggle('is-active', active); });
    qa('[data-navigate]').forEach(button => { const active = button.dataset.navigate === state.currentView || (state.currentView === 'booking' && button.dataset.navigate === 'home'); button.classList.toggle('is-active', active); active ? button.setAttribute('aria-current','page') : button.removeAttribute('aria-current'); });
    setTelegramBack(state.currentView !== 'home');
    hideTelegramMain();
    if (state.currentView === 'services') renderServices();
    if (state.currentView === 'bookings') { renderBookings(); if (!state.preview) loadBookings(); }
    q('[data-view]:not([hidden])')?.focus?.({preventScroll:true});
    window.scrollTo({top:0, behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
  };

  const renderServices = () => {
    const container = q('[data-services-list]');
    if (!container) return;
    if (!state.services.length) { container.innerHTML = `<div class="ta-empty">${escapeHtml(text('no_services'))}</div>`; return; }
    const canBook = capabilityEnabled('booking');
    container.innerHTML = state.services.map(service => `<article class="ta-service-card"><header><div><h2>${escapeHtml(serviceTitle(service))}</h2><p>${escapeHtml(service.summary || service.description || '')}</p></div><strong>${escapeHtml(formatPrice(service))}</strong></header><div class="ta-service-meta">${service.duration_minutes ? `<span class="ta-pill">${escapeHtml(text('duration',{minutes:service.duration_minutes}))}</span>` : ''}${modeLabel(service.mode) ? `<span class="ta-pill">${escapeHtml(modeLabel(service.mode))}</span>` : ''}${canBook?`<button class="ta-text-button" type="button" data-select-service="${escapeHtml(serviceId(service))}">${escapeHtml(text('book_this'))} →</button>`:''}</div></article>`).join('');
  };

  const renderBookingServices = () => {
    const container = q('[data-booking-services]');
    if (!container) return;
    if (!capabilityEnabled('booking')) { container.innerHTML = ''; return; }
    if (!state.services.length) { container.innerHTML = `<div class="ta-empty">${escapeHtml(text('no_services'))}</div>`; return; }
    container.innerHTML = state.services.map(service => `<button class="ta-choice" type="button" data-select-service="${escapeHtml(serviceId(service))}" aria-pressed="${state.booking.service && serviceId(state.booking.service) === serviceId(service) ? 'true':'false'}"><i aria-hidden="true"></i><span><strong>${escapeHtml(serviceTitle(service))}</strong><small>${escapeHtml([service.duration_minutes ? text('duration',{minutes:service.duration_minutes}) : '', modeLabel(service.mode)].filter(Boolean).join(' · '))}</small></span><b>${escapeHtml(formatPrice(service))}</b></button>`).join('');
  };

  const renderDateStrip = () => {
    const container = q('[data-date-strip]');
    if (!container) return;
    const zone = businessTimezone();
    const now = new Date();
    const days = Array.from({length:14}, (_, index) => {
      const date = new Date(now.getTime() + index * 86400000);
      const value = datePartsInZone(date, zone);
      return {value, label:dateLabel(value)};
    });
    if (!state.booking.date) state.booking.date = days[0].value;
    container.innerHTML = days.map(day => {
      const date = new Date(`${day.value}T12:00:00Z`);
      const number = Number(day.value.slice(-2));
      let weekday = '';
      try { weekday = new Intl.DateTimeFormat(state.locale === 'fa' ? 'fa-IR' : state.locale,{weekday:'short',timeZone:zone}).format(date); } catch (_) {}
      return `<button class="ta-date-button" type="button" role="listitem" data-booking-date="${day.value}" aria-pressed="${state.booking.date===day.value?'true':'false'}"><small>${escapeHtml(weekday)}</small><strong>${escapeHtml(number)}</strong></button>`;
    }).join('');
    const timezone = q('[data-timezone]');
    if (timezone) timezone.textContent = text('timezone',{timezone:zone});
  };

  const slotsForCurrentSelection = () => state.slots.filter(slot => {
    const matchesService = !state.booking.service || !slot.service_id || String(slot.service_id) === serviceId(state.booking.service);
    const slotDate = String(slot.local_date || slot.date || slot.start_at || '').slice(0,10);
    return matchesService && (!state.booking.date || slotDate === state.booking.date);
  });

  const renderSlots = () => {
    const container = q('[data-slot-list]');
    if (!container) return;
    const slots = slotsForCurrentSelection();
    if (!slots.length) { container.innerHTML = `<div class="ta-empty" style="grid-column:1/-1">${escapeHtml(text('no_slots'))}</div>`; return; }
    container.innerHTML = slots.map(slot => `<button class="ta-slot" type="button" data-select-slot="${escapeHtml(slotId(slot))}" aria-pressed="${state.booking.slot && slotId(state.booking.slot)===slotId(slot)?'true':'false'}"${slot.available===false || slot.status && !['open','available'].includes(slot.status)?' disabled':''}>${escapeHtml(slotTimeLabel(slot))}</button>`).join('');
  };

  const loadSlots = async () => {
    if (!capabilityEnabled('booking')) return;
    if (state.preview || !apiBase || !state.booking.service) { renderSlots(); return; }
    const query = new URLSearchParams({service_id:serviceId(state.booking.service), date:state.booking.date, timezone:businessTimezone()});
    loading(true);
    try {
      const data = await request(`${endpoint('slots','/slots')}?${query}`);
      state.slots = asArray(data);
      if (data?.timezone && state.profile.business) state.profile.business.timezone = data.timezone;
      renderSlots();
    } catch (error) { q('[data-slot-list]').innerHTML = `<div class="ta-empty" style="grid-column:1/-1">${escapeHtml(errorMessage(error))}</div>`; }
    finally { loading(false); }
  };

  const setBookingStep = step => {
    state.booking.step = Math.max(1, Math.min(5, Number(step) || 1));
    qa('[data-booking-step]').forEach(node => { node.hidden = Number(node.dataset.bookingStep) !== state.booking.step; });
    qa('[data-step-marker]').forEach(marker => {
      const number = Number(marker.dataset.stepMarker);
      marker.classList.toggle('is-current', number === Math.min(state.booking.step,4));
      marker.classList.toggle('is-complete', number < state.booking.step);
      number === Math.min(state.booking.step,4) ? marker.setAttribute('aria-current','step') : marker.removeAttribute('aria-current');
    });
    const counter = q('[data-step-counter]');
    if (counter) counter.textContent = text('step_of',{current:Math.min(state.booking.step,4),total:4});
    const back = q('[data-booking-back]');
    if (back) back.hidden = state.booking.step === 5;
    setTelegramBack(true);
    if (tg?.MainButton) {
      tg.MainButton.offClick?.(confirmBooking);
      if (state.booking.step === 4 && !state.preview) {
        tg.MainButton.setText?.(text('confirm_booking')); tg.MainButton.onClick?.(confirmBooking); tg.MainButton.show?.();
        const fallbackButton = q('[data-action="confirm-booking"]'); if (fallbackButton) fallbackButton.hidden = true;
      } else {
        tg.MainButton.hide?.();
        const fallbackButton = q('[data-action="confirm-booking"]'); if (fallbackButton) fallbackButton.hidden = false;
      }
    }
    q(`[data-booking-step="${state.booking.step}"]`)?.focus?.({preventScroll:true});
  };

  const startBooking = (service = null) => {
    if (!capabilityEnabled('booking')) return;
    state.booking = {step:1, service, date:'', slot:null, details:{}, hold:null};
    renderBookingServices(); renderDateStrip(); renderSlots(); navigate('booking');
    setBookingStep(service ? 2 : 1);
    if (service) loadSlots();
  };

  const selectService = id => {
    if (!capabilityEnabled('booking')) return;
    const service = state.services.find(item => serviceId(item) === String(id));
    if (!service) return;
    state.booking.service = service; state.booking.slot = null;
    renderBookingServices(); renderDateStrip();
    if (state.currentView !== 'booking') navigate('booking');
    setBookingStep(2); loadSlots();
  };

  const selectSlot = id => {
    if (!capabilityEnabled('booking')) return;
    const slot = state.slots.find(item => slotId(item) === String(id));
    if (!slot) return;
    state.booking.slot = slot; renderSlots(); setBookingStep(3);
  };

  const validateDetails = form => {
    qa('[aria-invalid="true"]', form).forEach(input => input.removeAttribute('aria-invalid'));
    qa('[data-error-for]', form).forEach(node => { node.textContent = ''; });
    const data = Object.fromEntries(new FormData(form));
    const errors = {};
    if (String(data.name || '').trim().length < 2) errors.name = text('field_required');
    if (data.phone && !/^[+0-9() .-]{6,40}$/.test(String(data.phone))) errors.phone = text('invalid_phone');
    if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(data.email))) errors.email = text('invalid_email');
    Object.entries(errors).forEach(([field,message]) => { const input = form.elements[field]; input?.setAttribute('aria-invalid','true'); const node=q(`[data-error-for="${field}"]`,form); if(node)node.textContent=message; });
    if (Object.keys(errors).length) { form.elements[Object.keys(errors)[0]]?.focus(); tg?.HapticFeedback?.notificationOccurred?.('error'); return false; }
    state.booking.details = cleanObject({name:String(data.name).trim(), phone:String(data.phone||'').trim(), email:String(data.email||'').trim(), notes:String(data.notes||'').trim()});
    return true;
  };

  const renderReview = () => {
    const service = state.booking.service || {};
    const slot = state.booking.slot || {};
    const contact = [state.booking.details.name, state.booking.details.phone, state.booking.details.email].filter(Boolean).join(' · ');
    q('[data-booking-review]').innerHTML = [
      [text('service'), `${serviceTitle(service)} · ${formatPrice(service)}`],
      [text('date_time'), dateTimeLabel(slot.start_at || `${state.booking.date}T${slot.local_time || ''}`, slot.end_at)],
      [text('contact'), contact],
    ].map(([term,value]) => `<div><dt>${escapeHtml(term)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('');
  };

  async function confirmBooking() {
    if (state.preview) { toast(text('preview_notice')); return; }
    if (!capabilityEnabled('booking')) return;
    if (!state.booking.service || !state.booking.slot) return;
    const baseKey = identifier('booking');
    const holdBody = cleanObject({service_id:serviceId(state.booking.service), slot_id:slotId(state.booking.slot), ...state.booking.details, timezone:businessTimezone(), idempotency_key:`${baseKey}-hold`});
    loading(true, text('holding'));
    tg?.MainButton?.showProgress?.();
    try {
      const held = await request(endpoint('reservation_hold','/reservations/hold'), {method:'POST', body:holdBody, idempotency:`${baseKey}-hold`});
      const reservation = held.reservation || held.booking || held;
      state.booking.hold = reservation;
      const reference = bookingReference(reservation);
      const confirmed = await request(endpoint('reservation_confirm','/reservations/confirm'), {method:'POST', body:{reference, idempotency_key:`${baseKey}-confirm`}, idempotency:`${baseKey}-confirm`});
      const finalBooking = confirmed.reservation || confirmed.booking || confirmed;
      state.bookings = [finalBooking, ...state.bookings.filter(item => bookingReference(item)!==bookingReference(finalBooking))];
      q('[data-booking-reference]').textContent = text('booking_reference',{reference:bookingReference(finalBooking)});
      setBookingStep(5); tg?.HapticFeedback?.notificationOccurred?.('success');
    } catch (error) {
      toast(errorMessage(error), 5000); tg?.HapticFeedback?.notificationOccurred?.('error');
      if (['slot_taken','slot_unavailable','slot_not_available'].includes(String(error.code))) { setBookingStep(2); await loadSlots(); }
    } finally { loading(false); tg?.MainButton?.hideProgress?.(); }
  }

  const renderBookings = () => {
    const container = q('[data-bookings-list]');
    if (!container) return;
    if (!state.bookings.length) { container.innerHTML = `<div class="ta-empty">${escapeHtml(text('no_bookings'))}</div>`; return; }
    container.innerHTML = state.bookings.map(booking => {
      const service = booking.service || state.services.find(item => serviceId(item)===String(booking.service_id)) || {};
      const slot = booking.slot || booking;
      const status = String(booking.status || 'requested').toLowerCase();
      const cancellable = !['cancelled','completed','no_show'].includes(status);
      return `<article class="ta-booking-card"><header><div><h2>${escapeHtml(serviceTitle(service) || booking.service_title || '')}</h2><p>${escapeHtml(dateTimeLabel(slot.start_at || booking.starts_at, slot.end_at || booking.ends_at))}</p></div><span class="ta-status is-${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</span></header><div class="ta-booking-meta"><span class="ta-pill">${escapeHtml(bookingReference(booking))}</span>${cancellable?`<button class="ta-text-button" type="button" data-cancel-booking="${escapeHtml(bookingReference(booking))}"${state.preview?' disabled':''}>${escapeHtml(text('cancel_booking'))}</button>`:''}</div></article>`;
    }).join('');
  };

  const loadBookings = async () => {
    if (!apiBase || state.preview) return;
    try { const data = await request(endpoint('reservations','/reservations')); state.bookings = asArray(data); renderBookings(); }
    catch (error) { toast(errorMessage(error)); }
  };

  const cancelBooking = async reference => {
    if (state.preview) { toast(text('preview_notice')); return; }
    const confirmed = tg?.showConfirm ? await new Promise(resolve => tg.showConfirm(text('cancel_confirm'), resolve)) : window.confirm(text('cancel_confirm'));
    if (!confirmed) return;
    const key = identifier('cancel'); loading(true);
    try {
      const data = await request(endpoint('reservation_cancel',`/reservations/${encodeURIComponent(reference)}/cancel`), {method:'POST',body:{idempotency_key:key},idempotency:key});
      const updated = data.reservation || data.booking || data;
      state.bookings = state.bookings.map(item => bookingReference(item)===reference ? {...item,...updated,status:updated.status||'cancelled'} : item);
      renderBookings(); toast(text('cancelled'));
    } catch (error) { toast(errorMessage(error)); }
    finally { loading(false); }
  };

  const openChat = () => {
    const url = String(state.profile?.assistant?.chat_url || state.profile?.chat_url || '');
    if (url.startsWith('https://t.me/')) {
      if (tg?.openTelegramLink) tg.openTelegramLink(url);
      else window.open(url, '_blank', 'noopener,noreferrer');
    } else if (tg) tg.close();
    else toast(text('connection_required'));
  };

  const requestHandoff = async () => {
    if (!capabilityEnabled('handoff')) return;
    if (state.preview) { toast(text('preview_notice')); return; }
    if (!apiBase) { openChat(); return; }
    loading(true);
    try { await request(endpoint('handoff','/handoff'), {method:'POST',body:{reason:'customer_requested'}}); toast(text('handoff_sent'),5000); }
    catch (_) { openChat(); }
    finally { loading(false); }
  };

  /* Manager interface */
  const adminNavigate = view => {
    if (state.mode !== 'admin') return;
    state.currentAdminView = ['inbox','reservations','services','slots','settings'].includes(view) ? view : 'inbox';
    qa('[data-admin-view]').forEach(node => { const active=node.dataset.adminView===state.currentAdminView; node.hidden=!active; node.classList.toggle('is-active',active); });
    qa('[data-admin-navigate]').forEach(button => { const active=button.dataset.adminNavigate===state.currentAdminView; button.classList.toggle('is-active',active); active?button.setAttribute('aria-current','page'):button.removeAttribute('aria-current'); });
    setTelegramBack(false); hideTelegramMain(); renderAdminView();
    if (!state.preview) loadAdminView(state.currentAdminView);
    q('[data-admin-view]:not([hidden])')?.focus?.({preventScroll:true});
    window.scrollTo({top:0,behavior:'auto'});
  };

  const conversationName = item => String(item?.contact?.name || item?.customer?.name || item?.name || item?.contact?.username || item?.id || '');
  const conversationMode = item => String(item?.mode || item?.status || item?.handoff?.status || 'automated');
  const serviceEnabled = item => ![false, 0, '0'].includes(item?.is_enabled ?? item?.enabled ?? true);
  const renderConversations = (filter = q('[data-inbox-filter] .is-active')?.dataset.filter || 'all') => {
    const container = q('[data-conversation-list]'); if (!container) return;
    const items = filter === 'all' ? state.conversations : state.conversations.filter(item => conversationMode(item)===filter || item?.handoff?.status===filter);
    const unread = state.conversations.reduce((sum,item)=>sum+Number(item.unread_count||0),0);
    const badge=q('[data-unread-total]'); if(badge){badge.textContent=String(unread);badge.hidden=unread<1;}
    if (!items.length) { container.innerHTML=`<div class="ta-empty">${escapeHtml(text('inbox_empty'))}</div>`; return; }
    container.innerHTML=items.map(item=>{const mode=conversationMode(item);const last=item.last_message||{};const name=conversationName(item);return `<button class="ta-conversation-card${state.conversation&&String(state.conversation.id)===String(item.id)?' is-active':''}" type="button" data-open-conversation="${escapeHtml(item.id)}"><span class="ta-contact-avatar" aria-hidden="true">${escapeHtml(name.slice(0,1).toUpperCase())}</span><span><strong>${escapeHtml(name)}</strong><small>${escapeHtml(last.text||item.preview||'')}</small></span>${Number(item.unread_count||0)>0?`<b class="ta-unread">${Number(item.unread_count)}</b>`:`<span class="ta-status is-${escapeHtml(mode)}">${escapeHtml(text(mode))}</span>`}</button>`}).join('');
  };

  const renderConversationPanel = () => {
    const panel=q('[data-conversation-panel]'); if(!panel)return;
    const item=state.conversation; panel.hidden=!item; if(!item)return;
    q('[data-conversation-name]',panel).textContent=conversationName(item);
    const mode=conversationMode(item); q('[data-conversation-status]',panel).textContent=text(mode);
    const takeover=q('[data-action="toggle-takeover"]',panel); takeover.textContent=mode==='human'?text('return_to_assistant'):text('take_over'); takeover.disabled=state.preview;
    const messages=asArray(item.messages);
    q('[data-message-list]',panel).innerHTML=messages.length?messages.map(message=>`<article class="ta-message ${message.direction==='outgoing'||['assistant','manager','human'].includes(message.actor)?'is-outgoing':''}"><p>${escapeHtml(message.text||'')}</p><time>${escapeHtml(dateTimeLabel(message.created_at))}</time></article>`).join(''):`<div class="ta-empty">${escapeHtml(text('inbox_empty'))}</div>`;
    const messageList=q('[data-message-list]',panel); messageList.scrollTop=messageList.scrollHeight;
  };

  const openConversation = async id => {
    state.conversation=state.conversations.find(item=>String(item.id)===String(id))||null;
    renderConversations(); renderConversationPanel();
    if (state.preview || !state.conversation || asArray(state.conversation.messages).length) return;
    loading(true);
    try { const data=await request(endpoint('conversation',`/admin/conversations/${encodeURIComponent(id)}`)); state.conversation=data.conversation||data; const index=state.conversations.findIndex(item=>String(item.id)===String(id));if(index>=0)state.conversations[index]={...state.conversations[index],...state.conversation};renderConversationPanel(); }
    catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const renderAdminReservations = (filter=q('[data-reservation-filter] .is-active')?.dataset.filter||'today') => {
    const container=q('[data-admin-reservations]');if(!container)return;
    const today=datePartsInZone(new Date(),businessTimezone());
    let items=state.bookings;
    if(filter==='today')items=items.filter(item=>String(item?.slot?.local_date||item?.local_date||item?.start_at||item?.starts_at||'').slice(0,10)===today);
    if(filter==='upcoming')items=items.filter(item=>{
      if(!['pending','confirmed'].includes(String(item.status||'').toLowerCase()))return false;
      const value=item?.slot?.start_at||item.start_at||item.starts_at||'';const time=Date.parse(value);
      return !Number.isFinite(time)||time>=Date.now();
    });
    if(!items.length){container.innerHTML=`<div class="ta-empty">${escapeHtml(text('admin_no_reservations'))}</div>`;return;}
    const disabled=state.preview?' disabled aria-disabled="true"':'';
    container.innerHTML=items.map(item=>{const status=String(item.status||'requested').toLowerCase();const displayStatus=reservationDisplayStatus(item);const service=item.service||{};const customer=item.customer||item.contact||{};const reference=bookingReference(item);return `<article class="ta-admin-card"><div><header><div><h2>${escapeHtml(customer.name||item.name||reference)}</h2><p>${escapeHtml(serviceTitle(service)||item.service_title||'')}</p></div><span class="ta-status is-${escapeHtml(displayStatus)}">${escapeHtml(statusLabel(displayStatus))}</span></header><div class="ta-admin-meta"><span class="ta-pill">${escapeHtml(dateTimeLabel(item?.slot?.start_at||item.start_at||item.starts_at,item?.slot?.end_at||item.end_at||item.ends_at))}</span><span class="ta-pill">${escapeHtml(reference)}</span></div></div><div class="ta-admin-card-actions">${status==='pending'?`<button type="button" data-booking-status="confirmed" data-booking-ref="${escapeHtml(reference)}"${disabled}>${escapeHtml(text('confirm'))}</button><button class="is-danger" type="button" data-reject-booking="${escapeHtml(reference)}"${disabled}>${escapeHtml(text('reject_reservation'))}</button>`:''}${status==='confirmed'?`<button type="button" data-booking-status="completed" data-booking-ref="${escapeHtml(reference)}"${disabled}>${escapeHtml(text('complete'))}</button><button class="is-danger" type="button" data-booking-status="cancelled" data-booking-ref="${escapeHtml(reference)}"${disabled}>${escapeHtml(text('cancel'))}</button>`:''}</div></article>`}).join('');
  };

  const renderAdminServices = () => {
    const container=q('[data-admin-services]');if(!container)return;
    container.innerHTML=state.services.length?state.services.map(item=>`<article class="ta-admin-card"><div><h2>${escapeHtml(serviceTitle(item))}</h2><p>${escapeHtml(item.summary||item.description||'')}</p><div class="ta-admin-meta"><span class="ta-pill">${escapeHtml(item.duration_minutes?text('duration',{minutes:item.duration_minutes}):'')}</span><span class="ta-pill">${escapeHtml(formatPrice(item))}</span><span class="ta-status">${escapeHtml(serviceEnabled(item)?text('enabled'):text('read_only'))}</span></div></div><div class="ta-admin-card-actions"><button type="button" data-edit-service="${escapeHtml(serviceId(item))}"${state.preview?' disabled aria-disabled="true"':''}>${escapeHtml(text('edit'))}</button></div></article>`).join(''):`<div class="ta-empty">${escapeHtml(text('no_services'))}</div>`;
    const options=state.services.map(item=>`<option value="${escapeHtml(serviceId(item))}">${escapeHtml(serviceTitle(item))}</option>`).join('');
    ['[data-slot-form] select[name="service_id"]','[data-availability-service]','[data-generation-service]'].forEach(selector=>{const select=q(selector);if(!select)return;const previous=String(select.value||'');select.innerHTML=options;if(previous&&state.services.some(item=>serviceId(item)===previous))select.value=previous;});
    const availabilitySelect=q('[data-availability-service]');
    if(availabilitySelect&&state.availabilityServiceId!==String(availabilitySelect.value||'')){state.availabilityServiceId='';state.availabilityRules=[];renderAvailabilityRules();}
  };

  const weekdayOptions = () => [
    [1,'weekday_monday'],[2,'weekday_tuesday'],[3,'weekday_wednesday'],[4,'weekday_thursday'],[5,'weekday_friday'],[6,'weekday_saturday'],[7,'weekday_sunday'],
  ].map(([value,key])=>`<option value="${value}">${escapeHtml(text(key))}</option>`).join('');

  const availabilityOccupiedMinutes = () => {const id=String(q('[data-availability-service]')?.value||'');const service=state.services.find(item=>serviceId(item)===id)||{};return Math.max(5,Number(service.duration_minutes||0)+Number(service.buffer_before_minutes||0)+Number(service.buffer_after_minutes||0));};
  const blankAvailabilityRule = () => ({weekday:1,start_time:'09:00',end_time:'17:00',slot_interval_minutes:Math.ceil(availabilityOccupiedMinutes()/5)*5,effective_from:'',effective_until:'',is_enabled:true});

  const availabilityReady = () => {const selected=String(q('[data-availability-service]')?.value||'');return !state.preview&&selected!==''&&selected===state.availabilityServiceId;};
  const setAvailabilityReady = ready => {const form=q('[data-availability-form]');if(!form)return;[q('button[type="submit"]',form),q('[data-action="add-availability-window"]',form)].forEach(button=>{if(!button)return;button.disabled=state.preview||!ready;button.disabled?button.setAttribute('aria-disabled','true'):button.removeAttribute('aria-disabled');});};

  const renderAvailabilityRules = () => {
    const container=q('[data-weekly-rule-list]');if(!container)return;
    const ready=availabilityReady();const minimumInterval=availabilityOccupiedMinutes();setAvailabilityReady(ready);
    if(!state.availabilityRules.length){container.innerHTML=`<div class="ta-empty ta-schedule-empty">${escapeHtml(text('no_schedule_rules'))}</div>`;return;}
    const disabled=!ready?' disabled aria-disabled="true"':'';
    container.innerHTML=state.availabilityRules.map((rule,index)=>`<fieldset class="ta-weekly-rule" data-weekly-rule="${index}"><legend>${escapeHtml(text('weekday'))} ${index+1}</legend><label><span>${escapeHtml(text('weekday'))}</span><select data-rule-field="weekday"${disabled}>${weekdayOptions()}</select></label><label><span>${escapeHtml(text('start_time'))}</span><input type="time" data-rule-field="start_time" value="${escapeHtml(String(rule.start_time||'09:00').slice(0,5))}" required${disabled}></label><label><span>${escapeHtml(text('end_time'))}</span><input type="time" data-rule-field="end_time" value="${escapeHtml(String(rule.end_time||'17:00').slice(0,5))}" required${disabled}></label><label><span>${escapeHtml(text('slot_interval'))}</span><input type="number" min="${escapeHtml(minimumInterval)}" max="1440" step="1" data-rule-field="slot_interval_minutes" value="${escapeHtml(Number(rule.slot_interval_minutes||Math.ceil(minimumInterval/5)*5))}" required${disabled}></label><label><span>${escapeHtml(text('effective_from'))}</span><input type="date" data-rule-field="effective_from" value="${escapeHtml(rule.effective_from||'')}"${disabled}></label><label><span>${escapeHtml(text('effective_until'))}</span><input type="date" data-rule-field="effective_until" value="${escapeHtml(rule.effective_until||'')}"${disabled}></label><label class="ta-check"><input type="checkbox" data-rule-field="is_enabled"${enabledFlag(rule.is_enabled??true)?' checked':''}${disabled}><span>${escapeHtml(text('enabled'))}</span></label><button class="ta-text-button ta-remove-rule" type="button" data-remove-availability-window="${index}"${disabled}>${escapeHtml(text('remove_time_window'))}</button></fieldset>`).join('');
    qa('[data-weekly-rule]',container).forEach((row,index)=>{const select=q('[data-rule-field="weekday"]',row);if(select)select.value=String(state.availabilityRules[index]?.weekday||1);});
  };

  const loadAvailabilityRules = async (silent=false) => {
    const select=q('[data-availability-service]');const id=String(select?.value||'');state.availabilityServiceId='';state.availabilityRules=[];renderAvailabilityRules();
    if(!id||state.preview||!apiBase)return;
    if(!silent)loading(true);
    try{const data=await request(endpoint('admin_service_availability',`/admin/services/${encodeURIComponent(id)}/availability`));if(String(select?.value||'')!==id)return;state.availabilityServiceId=id;state.availabilityRules=asArray(data.rules);renderAvailabilityRules();}
    catch(error){toast(errorMessage(error));}
    finally{if(!silent)loading(false);}
  };

  const readAvailabilityRules = form => qa('[data-weekly-rule]',form).map(row=>({
    weekday:Number(q('[data-rule-field="weekday"]',row)?.value||0),
    start_time:String(q('[data-rule-field="start_time"]',row)?.value||''),
    end_time:String(q('[data-rule-field="end_time"]',row)?.value||''),
    slot_interval_minutes:Number(q('[data-rule-field="slot_interval_minutes"]',row)?.value||0),
    effective_from:String(q('[data-rule-field="effective_from"]',row)?.value||'')||null,
    effective_until:String(q('[data-rule-field="effective_until"]',row)?.value||'')||null,
    is_enabled:Boolean(q('[data-rule-field="is_enabled"]',row)?.checked),
  }));

  const saveAvailabilityRules = async form => {
    if(state.preview)return;const id=String(q('[data-availability-service]',form)?.value||'');if(!id||id!==state.availabilityServiceId)return;
    loading(true);
    try{const data=await request(endpoint('admin_service_availability',`/admin/services/${encodeURIComponent(id)}/availability`),{method:'POST',body:{rules:readAvailabilityRules(form)}});state.availabilityServiceId=id;state.availabilityRules=asArray(data.rules);renderAvailabilityRules();toast(text('schedule_saved'));}
    catch(error){toast(errorMessage(error));}
    finally{loading(false);}
  };

  const dateWithOffset = days => {const today=datePartsInZone(new Date(),businessTimezone());const date=new Date(`${today}T12:00:00Z`);date.setUTCDate(date.getUTCDate()+days);return date.toISOString().slice(0,10);};
  const setGenerationDefaults = () => {const form=q('[data-slot-generation-form]');if(!form)return;if(!form.elements.from_date.value)form.elements.from_date.value=dateWithOffset(1);if(!form.elements.until_date.value)form.elements.until_date.value=dateWithOffset(30);};

  const generateAdminSlots = async form => {
    if(state.preview)return;const raw=serializeAdminForm(form);const service=String(raw.service_id||'');if(!service||!window.confirm(text('generate_slots_confirm')))return;
    loading(true);
    try{const data=await request(endpoint('admin_slots_generate',`/admin/services/${encodeURIComponent(service)}/slots/generate`),{method:'POST',body:{from_date:String(raw.from_date||''),until_date:String(raw.until_date||'')}});state.slots=asArray(data.items);renderAdminSlots();const result=data.result||{};const node=q('[data-slot-generation-result]');if(node){node.textContent=text('slots_generated',{created:Number(result.created||0),existing:Number(result.existing||0),skipped:Number(result.skipped||0)});node.hidden=false;}await loadAdminSummary(true);}
    catch(error){toast(errorMessage(error));}
    finally{loading(false);}
  };

  const renderAdminSlots = () => {
    const container=q('[data-admin-slots]');if(!container)return;
    if(!state.slots.length){container.innerHTML=`<div class="ta-empty">${escapeHtml(text('no_slots'))}</div>`;return;}
    container.innerHTML=state.slots.map(item=>{const service=state.services.find(s=>serviceId(s)===String(item.service_id))||{};const status=String(item.status||'available').toLowerCase();return `<article class="ta-admin-card"><div><h2>${escapeHtml(dateTimeLabel(item.start_at,item.end_at))}</h2><p>${escapeHtml(serviceTitle(service))}</p></div><div class="ta-admin-card-actions"><span class="ta-status is-${escapeHtml(status)}">${escapeHtml(slotStatusLabel(status))}</span>${['open','available'].includes(status)?`<button class="is-danger" type="button" data-block-slot="${escapeHtml(slotId(item))}"${state.preview?' disabled aria-disabled="true"':''}>${escapeHtml(text('block_slot'))}</button>`:''}</div></article>`}).join('');
  };

  const renderAdminSettings = () => {
    const form=q('[data-settings-form]');if(!form)return;
    const settings=state.profile?.settings||{};
    const assistant=state.profile?.assistant||{};
    const business=state.profile?.business||{};
    form.elements.name.value=String(settings.assistant_name??assistant.name??'');
    form.elements.welcome.value=String(settings.welcome_message??assistant.welcome??'');
    form.elements.timezone.value=String(settings.timezone??business.timezone??businessTimezone());
    form.elements.profile_enabled.checked=enabledFlag(settings.profile_enabled??false);
    form.elements.auto_reply.checked=enabledFlag(settings.auto_reply_enabled??settings.auto_reply??false);
    form.elements.handoff_unknown.checked=enabledFlag(settings.handoff_unknown_enabled??settings.handoff_unknown??false);
    const miniApp=q('[data-mini-app-url]');if(miniApp)miniApp.value=state.miniAppUrl;
    renderBusinessConnections();
  };

  const renderBusinessConnections = () => {
    const container=q('[data-business-connections]');if(!container)return;
    if(!state.businessConnections.length){container.innerHTML=`<div class="ta-empty">${escapeHtml(text('no_business_connections'))}</div>`;return;}
    container.innerHTML=state.businessConnections.map(item=>{
      const status=['pending','authorized','rejected'].includes(String(item.authorization_status))?String(item.authorization_status):'pending';
      const disabled=state.preview||!item.telegram_enabled||!item.rights_ready||item.owner_matches_pin===false;
      const mismatch=item.owner_matches_pin===false?`<p>${escapeHtml(text('business_owner_mismatch'))}</p>`:'';
      const actions=status==='pending'?`<div class="ta-admin-card-actions"><button type="button" data-business-review="allow" data-business-id="${escapeHtml(item.id)}"${disabled?' disabled aria-disabled="true"':''}>${escapeHtml(text('approve_connection'))}</button><button class="is-danger" type="button" data-business-review="reject" data-business-id="${escapeHtml(item.id)}"${state.preview?' disabled aria-disabled="true"':''}>${escapeHtml(text('reject_connection'))}</button></div>`:'';
      return `<article class="ta-admin-card"><div><header><div><h2>${escapeHtml(text('business_connection_ref',{reference:item.reference||''}))}</h2><p>${escapeHtml(text('business_owner'))}: <b dir="ltr">${escapeHtml(item.owner_id_masked||'')}</b></p></div><span class="ta-status is-${escapeHtml(status)}">${escapeHtml(text(`business_status_${status}`))}</span></header><div class="ta-admin-meta"><span class="ta-pill">${escapeHtml(text(item.telegram_enabled?'business_telegram_enabled':'business_telegram_disabled'))}</span><span class="ta-pill">${escapeHtml(text(item.rights_ready?'business_can_reply':'business_cannot_reply'))}</span></div>${mismatch}</div>${actions}</article>`;
    }).join('');
  };

  const noticeCopyKey = id => ({
    'pending-reservations':'notice_pending_reservations',
    'waiting-handoffs':'notice_waiting_handoffs',
    'no-slots':'notice_no_slots',
    'business-connection':'notice_business_connection',
  })[String(id)] || 'admin_notifications';

  const renderAdminNotices = () => {
    const notices=asArray(state.adminSummary?.notices);const container=q('[data-admin-notice-list]');const badge=q('[data-admin-notice-total]');
    if(badge){badge.textContent=String(notices.length);badge.hidden=notices.length<1;}
    if(!container)return;
    container.innerHTML=notices.length?notices.map(item=>`<button class="ta-admin-notice is-${escapeHtml(item.severity||'info')}" type="button" data-notice-target="${escapeHtml(item.target||'inbox')}"><span>${escapeHtml(text(noticeCopyKey(item.id)))}</span>${Number(item.count||0)>0?`<b>${Number(item.count)}</b>`:''}</button>`).join(''):`<div class="ta-empty">${escapeHtml(text('admin_notifications_empty'))}</div>`;
  };

  const loadAdminSummary = async (silent=false) => {
    if(state.preview||!apiBase){renderAdminNotices();return;}
    if(!silent)loading(true);
    try{const data=await request(endpoint('admin_summary','/admin/summary'));state.adminSummary={counts:data.counts||{},notices:asArray(data.notices)};renderAdminNotices();}
    catch(error){toast(errorMessage(error));}
    finally{if(!silent)loading(false);}
  };

  const renderAdminView = () => {
    if(state.currentAdminView==='inbox'){renderConversations();renderConversationPanel();}
    if(state.currentAdminView==='reservations')renderAdminReservations();
    if(state.currentAdminView==='services')renderAdminServices();
    if(state.currentAdminView==='slots')renderAdminSlots();
    if(state.currentAdminView==='settings')renderAdminSettings();
  };

  const loadAdminView = async view => {
    if(!apiBase||state.preview)return;
    if(view==='settings'){
      try{
        const [settingsData,connectionData]=await Promise.all([
          request(endpoint('admin_settings','/admin/settings')),
          request(endpoint('admin_business_connections','/admin/business-connections')),
        ]);
        state.profile={...state.profile,...(settingsData.profile||{}),settings:{...(state.profile.settings||{}),...(settingsData.settings||{})}};
        state.miniAppUrl=String(settingsData.mini_app_url||state.miniAppUrl||'');state.businessConnections=asArray(connectionData);renderAdminView();
      }catch(error){toast(errorMessage(error));}
      return;
    }
    const mapping={inbox:['conversations','/admin/conversations'],reservations:['admin_reservations','/admin/reservations'],services:['admin_services','/admin/services'],slots:['admin_slots','/admin/slots'],settings:['admin_settings','/admin/settings']};
    const [name,path]=mapping[view]||[];if(!name)return;
    try{const data=await request(endpoint(name,path));if(view==='inbox')state.conversations=asArray(data);if(view==='reservations')state.bookings=asArray(data);if(view==='services')state.services=asArray(data);if(view==='slots')state.slots=asArray(data);if(view==='settings'){state.profile={...state.profile,...(data.profile||{}),settings:{...(state.profile.settings||{}),...(data.settings||{})}};}renderAdminView();if(view==='slots')await loadAvailabilityRules(true);}catch(error){toast(errorMessage(error));}
  };

  const saveConversationAction = async action => {
    if(state.preview||!state.conversation){toast(text('preview_notice'));return;}
    const id=state.conversation.id;loading(true);
    try{const data=await request(endpoint(`conversation_${action}`,`/admin/conversations/${encodeURIComponent(id)}/${action}`),{method:'POST',body:{}});state.conversation={...state.conversation,...(data.conversation||data),mode:data.mode||({takeover:'human',release:'automated',close:'closed'}[action])};state.conversations=state.conversations.map(item=>String(item.id)===String(id)?{...item,...state.conversation}:item);renderConversations();renderConversationPanel();}catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const sendReply = async form => {
    const value=String(new FormData(form).get('text')||'').trim();if(!value||state.preview||!state.conversation)return;
    const id=state.conversation.id;const key=identifier('reply');
    try{const data=await request(endpoint('conversation_reply',`/admin/conversations/${encodeURIComponent(id)}/reply`),{method:'POST',body:{text:value,idempotency_key:key},idempotency:key});const message=data.message||{id:key,text:value,direction:'outgoing',actor:'manager',created_at:new Date().toISOString()};state.conversation.messages=[...asArray(state.conversation.messages),message];form.reset();renderConversationPanel();}catch(error){toast(errorMessage(error));}
  };

  const updateReservationStatus = async (reference,status) => {
    if(state.preview)return;const key=identifier('status');loading(true);
    try{const data=await request(endpoint('admin_reservation_status',`/admin/reservations/${encodeURIComponent(reference)}/status`),{method:'POST',body:{status,idempotency_key:key},idempotency:key});const updated=data.reservation||data;state.bookings=state.bookings.map(item=>bookingReference(item)===reference?{...item,...updated,status:updated.status||status}:item);renderAdminReservations();toast(text('saved'));await loadAdminSummary(true);}catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const rejectReservation = async reference => {
    if(state.preview||!reference||!window.confirm(text('reject_reservation_confirm')))return;loading(true);
    try{const data=await request(endpoint('admin_reservation_reject',`/admin/reservations/${encodeURIComponent(reference)}/reject`),{method:'POST',body:{reason_code:'operator_rejected'}});const updated=data.reservation||data;state.bookings=state.bookings.map(item=>bookingReference(item)===reference?{...item,...updated,status:updated.status||'cancelled',resolution:'rejected'}:item);renderAdminReservations();toast(text('reservation_rejected'));await loadAdminSummary(true);}
    catch(error){toast(errorMessage(error));}
    finally{loading(false);}
  };

  const serializeAdminForm = form => Object.fromEntries(new FormData(form));
  const saveService = async form => {
    if(state.preview)return;const raw=serializeAdminForm(form);const id=String(raw.id||'');const body=cleanObject({id:id||undefined,title:String(raw.title||'').trim(),summary:String(raw.summary||'').trim(),duration_minutes:Number(raw.duration_minutes||0),price_amount:raw.price_amount===''?null:Number(raw.price_amount),currency:String(raw.currency||'RUB').toUpperCase(),is_enabled:raw.enabled==='1'});loading(true);
    try{const data=await request(endpoint('admin_service_save',`/admin/services${id?`/${encodeURIComponent(id)}`:''}`),{method:'POST',body});const saved=data.service||data;state.services=id?state.services.map(item=>serviceId(item)===id?{...item,...saved}:item):[...state.services,saved];form.hidden=true;form.reset();renderAdminServices();toast(text('saved'));}catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const saveSlot = async form => {
    if(state.preview)return;const raw=serializeAdminForm(form);const body={service_id:raw.service_id,start_at:raw.start_at,end_at:raw.end_at,timezone:businessTimezone()};loading(true);
    try{const data=await request(endpoint('admin_slot_save','/admin/slots'),{method:'POST',body});state.slots=[...state.slots,data.slot||data];form.hidden=true;form.reset();renderAdminSlots();toast(text('saved'));await loadAdminSummary(true);}catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const saveSettings = async form => {
    if(state.preview)return;const raw=serializeAdminForm(form);
    // Explicit API mapping: UI names become backend profile keys here.
    const body={assistant_name:String(raw.name||'').trim(),welcome_message:String(raw.welcome||'').trim(),timezone:String(raw.timezone||'').trim(),profile_enabled:raw.profile_enabled==='1',auto_reply_enabled:raw.auto_reply==='1',handoff_unknown_enabled:raw.handoff_unknown==='1'};
    loading(true);try{const data=await request(endpoint('admin_settings_save','/admin/settings'),{method:'POST',body});state.profile={...state.profile,...(data.profile||{}),settings:{...(state.profile.settings||{}),...(data.settings||body)}};state.businessConnections=asArray(data.business_connections);state.miniAppUrl=String(data.mini_app_url||state.miniAppUrl||'');renderAdminSettings();toast(text('saved'));}catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const reviewBusinessConnection = async (id,decision) => {
    if(state.preview||!['allow','reject'].includes(decision))return;loading(true);
    try{const data=await request(endpoint('admin_business_review',`/admin/business-connections/${encodeURIComponent(id)}/${decision}`),{method:'POST',body:{}});const reviewed=data.connection||data;state.businessConnections=state.businessConnections.map(item=>String(item.id)===String(id)?reviewed:item);renderBusinessConnections();toast(reviewed.owner_matches_pin===false?text('business_owner_mismatch'):text('saved'));}catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const copyMiniAppUrl = async () => {
    const input=q('[data-mini-app-url]');const value=String(input?.value||'').trim();if(!value)return;
    try{await navigator.clipboard.writeText(value);}catch(_){input.focus();input.select();document.execCommand('copy');}
    toast(text('copied'));
  };

  const blockSlot = async id => {
    if(state.preview)return;loading(true);try{const data=await request(endpoint('admin_slot_block',`/admin/slots/${encodeURIComponent(id)}/block`),{method:'POST',body:{}});state.slots=state.slots.map(item=>slotId(item)===String(id)?{...item,...(data.slot||data),status:(data.slot||data).status||'blocked'}:item);renderAdminSlots();await loadAdminSummary(true);}catch(error){toast(errorMessage(error));}finally{loading(false);}
  };

  const bootstrapSession = async () => {
    if(state.mode!=='customer'||state.preview||!apiBase)return;
    if(!tg?.initData){if(!Object.keys(state.profile).length)toast(text('connection_required'),6000);return;}
    loading(true);
    try{
      const session=await request(endpoint('session','/session'),{method:'POST',body:{init_data:tg.initData},auth:false,csrf:false});
      if(!session.token)throw Object.assign(new Error(text('session_expired')),{code:'invalid_session'});
      state.sessionToken=String(session.token);
      if(session.csrf)state.csrf=String(session.csrf);
      if(session.profile)state.profile={...state.profile,...session.profile};
      const profileRequest=request(endpoint('profile','/profile')).catch(()=>null);
      const servicesRequest=request(endpoint('services','/services')).catch(()=>null);
      const bookingsRequest=state.mode==='customer'?request(endpoint('reservations','/reservations')).catch(()=>null):Promise.resolve(null);
      const [profileData,servicesData,bookingsData]=await Promise.all([profileRequest,servicesRequest,bookingsRequest]);
      if(profileData)state.profile={...state.profile,...(profileData.profile||profileData)};
      if(servicesData)state.services=asArray(servicesData);
      if(bookingsData)state.bookings=asArray(bookingsData);
      if(state.mode==='customer'){syncCapabilities();renderServices();renderBookingServices();renderBookings();hydrateGreeting();}
      else{renderAdminView();}
    }catch(error){toast(errorMessage(error),6000);}finally{loading(false);}
  };

  const hydrateGreeting = () => {
    const name=String(state.profile?.customer?.first_name||'').trim();const node=q('[data-greeting]');if(node)node.textContent=name?text('hello',{name}):text('hello_guest');
  };

  const handleClick = event => {
    const target=event.target.closest('button,[data-action],[data-navigate],[data-admin-navigate]');if(!target)return;
    if(target.dataset.navigate){navigate(target.dataset.navigate);return;}
    if(target.dataset.adminNavigate){adminNavigate(target.dataset.adminNavigate);return;}
    if(target.dataset.selectService){selectService(target.dataset.selectService);return;}
    if(target.dataset.bookingDate){state.booking.date=target.dataset.bookingDate;state.booking.slot=null;renderDateStrip();loadSlots();return;}
    if(target.dataset.selectSlot){selectSlot(target.dataset.selectSlot);return;}
    if(target.dataset.cancelBooking){cancelBooking(target.dataset.cancelBooking);return;}
    if(target.dataset.openConversation){openConversation(target.dataset.openConversation);return;}
    if(target.dataset.bookingStatus){updateReservationStatus(target.dataset.bookingRef,target.dataset.bookingStatus);return;}
    if(target.dataset.rejectBooking){rejectReservation(target.dataset.rejectBooking);return;}
    if(target.dataset.noticeTarget){q('[data-admin-notice-center]').hidden=true;adminNavigate(target.dataset.noticeTarget);return;}
    if(target.dataset.removeAvailabilityWindow!==undefined){if(!availabilityReady())return;const form=q('[data-availability-form]');if(form)state.availabilityRules=readAvailabilityRules(form);const index=Number(target.dataset.removeAvailabilityWindow);if(Number.isInteger(index)&&index>=0){state.availabilityRules.splice(index,1);renderAvailabilityRules();}return;}
    if(target.dataset.businessReview){reviewBusinessConnection(target.dataset.businessId,target.dataset.businessReview);return;}
    if(target.dataset.editService){const item=state.services.find(service=>serviceId(service)===target.dataset.editService);const form=q('[data-service-form]');if(item&&form){form.hidden=false;form.elements.id.value=serviceId(item);form.elements.title.value=serviceTitle(item);form.elements.summary.value=item.summary||'';form.elements.duration_minutes.value=item.duration_minutes||'';form.elements.price_amount.value=item?.price?.amount??item.price_amount??'';form.elements.currency.value=item?.price?.currency||item.currency||'RUB';form.elements.enabled.checked=serviceEnabled(item);form.elements.title.focus();}return;}
    if(target.dataset.blockSlot){blockSlot(target.dataset.blockSlot);return;}
    const action=target.dataset.action;
    if(action==='start-booking')startBooking();
    if(action==='open-chat')openChat();
    if(action==='handoff')requestHandoff();
    if(action==='confirm-booking')confirmBooking();
    if(action==='close-conversation'){q('[data-conversation-panel]').hidden=true;}
    if(action==='toggle-takeover')saveConversationAction(conversationMode(state.conversation)==='human'?'release':'takeover');
    if(action==='mark-conversation-closed')saveConversationAction('close');
    if(action==='new-service'){const form=q('[data-service-form]');form.hidden=false;form.reset();form.elements.id.value='';form.elements.title.focus();}
    if(action==='cancel-service-edit')q('[data-service-form]').hidden=true;
    if(action==='new-slot'){renderAdminServices();const form=q('[data-slot-form]');form.hidden=false;form.elements.start_at.focus();}
    if(action==='cancel-slot-edit')q('[data-slot-form]').hidden=true;
    if(action==='add-availability-window'&&availabilityReady()){const form=q('[data-availability-form]');if(form)state.availabilityRules=readAvailabilityRules(form);state.availabilityRules.push(blankAvailabilityRule());renderAvailabilityRules();q('[data-weekly-rule]:last-child input, [data-weekly-rule]:last-child select')?.focus();}
    if(action==='toggle-admin-notices'){const panel=q('[data-admin-notice-center]');if(panel){panel.hidden=!panel.hidden;if(!panel.hidden)loadAdminSummary(true);}}
    if(action==='close-admin-notices')q('[data-admin-notice-center]').hidden=true;
    if(action==='refresh-admin'){loadAdminView(state.currentAdminView);loadAdminSummary(true);}
    if(action==='copy-mini-app-url')copyMiniAppUrl();
  };

  document.addEventListener('click',handleClick);
  q('[data-booking-back]')?.addEventListener('click',()=>{if(state.booking.step>1)setBookingStep(state.booking.step-1);else navigate('home');});
  q('[data-details-form]')?.addEventListener('submit',event=>{event.preventDefault();if(validateDetails(event.currentTarget)){renderReview();setBookingStep(4);}});
  q('[data-reply-form]')?.addEventListener('submit',event=>{event.preventDefault();sendReply(event.currentTarget);});
  q('[data-service-form]')?.addEventListener('submit',event=>{event.preventDefault();saveService(event.currentTarget);});
  q('[data-slot-form]')?.addEventListener('submit',event=>{event.preventDefault();saveSlot(event.currentTarget);});
  q('[data-availability-form]')?.addEventListener('submit',event=>{event.preventDefault();saveAvailabilityRules(event.currentTarget);});
  q('[data-slot-generation-form]')?.addEventListener('submit',event=>{event.preventDefault();generateAdminSlots(event.currentTarget);});
  q('[data-availability-service]')?.addEventListener('change',()=>loadAvailabilityRules());
  q('[data-settings-form]')?.addEventListener('submit',event=>{event.preventDefault();saveSettings(event.currentTarget);});
  q('[data-inbox-filter]')?.addEventListener('click',event=>{const button=event.target.closest('[data-filter]');if(!button)return;qa('button',event.currentTarget).forEach(item=>item.classList.toggle('is-active',item===button));renderConversations(button.dataset.filter);});
  q('[data-reservation-filter]')?.addEventListener('click',event=>{const button=event.target.closest('[data-filter]');if(!button)return;qa('button',event.currentTarget).forEach(item=>item.classList.toggle('is-active',item===button));renderAdminReservations(button.dataset.filter);});

  if(tg){tg.ready();tg.expand();tg.setHeaderColor?.('bg_color');tg.setBackgroundColor?.('bg_color');tg.setBottomBarColor?.('bg_color');tg.enableClosingConfirmation?.();tg.BackButton?.onClick?.(()=>{if(state.mode==='admin'){if(!q('[data-conversation-panel]')?.hidden)q('[data-conversation-panel]').hidden=true;}else if(state.currentView==='booking'&&state.booking.step>1)setBookingStep(state.booking.step-1);else if(state.currentView!=='home')navigate('home');else tg.close();});}

  root.lang=state.locale;root.dir=initial.direction||((state.locale==='fa')?'rtl':'ltr');
  if(state.mode==='customer'){syncCapabilities();hydrateGreeting();renderServices();renderBookingServices();renderDateStrip();renderSlots();renderBookings();navigate('home');}
  else{renderAdminServices();renderAdminSlots();renderAvailabilityRules();setGenerationDefaults();renderAdminNotices();renderAdminView();adminNavigate('inbox');loadAdminSummary(true);}
  bootstrapSession();

  window.__VazinTelegramAssistant={navigate,adminNavigate,renderServices,renderBookings,startBooking};
})();
