(() => {
  'use strict';
  const root = document.querySelector('[data-template-builder]');
  const form = document.getElementById('template-builder-form');
  if (!root || !form) return;

  const library = root.querySelector('[data-component-library]');
  const payload = root.querySelector('[data-template-payload]');
  const preview = root.querySelector('[data-template-preview]');
  const previewHeader = root.querySelector('[data-preview-part="header"]');
  const previewFooter = root.querySelector('[data-preview-part="footer"]');
  const previewStatus = root.querySelector('[data-preview-status]');
  const typeLabels = {
    brand: 'نام و نشان', nav: 'فهرست سایت', button: 'دکمهٔ پیوند',
    text: 'متن', contact: 'اطلاعات تماس', social: 'شبکه‌های اجتماعی',
    copyright: 'حق نشر'
  };
  let dirty = false;
  let previewWidth = 'desktop';

  const el = (tag, attrs = {}, text = null) => {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => {
      if (key === 'class') node.className = value;
      else if (key === 'value') node.value = value;
      else if (key.startsWith('data-')) node.setAttribute(key, value);
      else if (key === 'readOnly') node.readOnly = true;
      else if (key === 'type' || key === 'maxLength' || key === 'dir' || key === 'rows') node[key] = value;
      else node.setAttribute(key, String(value));
    });
    if (text !== null) node.textContent = String(text);
    return node;
  };
  const field = (label, key, value, multiline = false, direction = '') => {
    const wrap = el('label', {}, label);
    const control = multiline ? el('textarea', { rows: 3, maxLength: key === 'text' ? 300 : 160, 'data-prop': key }) :
      el('input', { type: 'text', maxLength: key === 'href' ? 240 : 160, dir: direction, 'data-prop': key });
    control.value = value || '';
    wrap.append(control);
    return wrap;
  };
  const socialFields = (links = []) => {
    const wrap = el('div', { class: 'vz-template-social-fields-list' });
    for (let i = 0; i < 4; i++) {
      const item = links[i] || { label: '', url: '' };
      const row = el('div', { class: 'vz-template-social-fields' });
      const label = el('label', {}, 'عنوان پیوند ' + (i + 1));
      const name = el('input', { type: 'text', maxLength: 30, 'data-social-label': '1' });
      name.value = item.label || '';
      label.append(name);
      const urlLabel = el('label', {}, 'نشانی HTTPS');
      const url = el('input', { type: 'url', dir: 'ltr', maxLength: 500, 'data-social-url': '1' });
      url.value = item.url || '';
      urlLabel.append(url);
      row.append(label, urlLabel);
      wrap.append(row);
    }
    return wrap;
  };
  const componentData = card => {
    const id = card.dataset.componentId;
    const type = card.querySelector('[data-component-type]').value;
    const label = card.querySelector('[data-component-label]').value.trim();
    const props = {};
    card.querySelectorAll('[data-prop]').forEach(control => { props[control.dataset.prop] = control.value; });
    if (type === 'social') {
      props.links = [...card.querySelectorAll('.vz-template-social-fields')].map(row => ({
        label: row.querySelector('[data-social-label]').value.trim(),
        url: row.querySelector('[data-social-url]').value.trim()
      })).filter(link => link.label || link.url);
    }
    return [id, { type, label, props }];
  };
  const cards = () => [...library.querySelectorAll('[data-component-card]')];
  const getComponents = () => Object.fromEntries(cards().map(componentData));
  const partList = part => root.querySelector('[data-part-list="' + part + '"]');
  const partIds = part => [...partList(part).querySelectorAll('[data-part-item]')].map(item => item.dataset.componentId);
  const idFor = () => {
    const bytes = new Uint8Array(4);
    if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    return 'c-' + [...bytes].map(n => n.toString(16).padStart(2, '0')).join('');
  };
  const updateCounts = () => {
    const count = cards().length;
    const countNode = root.querySelector('[data-component-count]');
    if (countNode) countNode.textContent = count + ' / 16';
    ['header', 'footer'].forEach(part => {
      const heading = root.querySelector('#' + part + '-title')?.parentElement?.parentElement?.querySelector('span');
      if (heading) heading.textContent = partIds(part).length + ' / 8';
    });
  };
  const addCard = (id, type, label, props = {}) => {
    const card = el('article', { class: 'vz-template-component-card', 'data-component-card': '1', 'data-component-id': id });
    const head = el('div', { class: 'vz-template-component-card-head' });
    const title = el('strong', { 'data-component-title': '1' }, label);
    head.append(title, el('code', {}, id));
    const remove = el('button', { type: 'button', class: 'danger', 'data-remove-component': '1' }, 'حذف جزء');
    head.append(remove);
    const fields = el('div', { class: 'vz-template-component-fields' });
    fields.append(field('شناسه', 'unused', id));
    const idInput = fields.lastElementChild.querySelector('input');
    idInput.removeAttribute('data-prop');
    idInput.readOnly = true;
    const typeWrap = el('label', {}, 'نوع جزء');
    const typeSelect = el('select', { 'data-component-type': '1' });
    Object.entries(typeLabels).forEach(([value, name]) => {
      const option = el('option', { value }, name);
      if (value === type) option.selected = true;
      typeSelect.append(option);
    });
    typeWrap.append(typeSelect);
    fields.append(typeWrap);
    fields.append(field('نام', 'unused', label));
    const labelInput = fields.lastElementChild.querySelector('input');
    labelInput.removeAttribute('data-prop');
    labelInput.dataset.componentLabel = '1';
    labelInput.maxLength = 50;
    const propsArea = el('div', { class: 'vz-template-component-props', 'data-component-props': '1' });
    fields.append(propsArea);
    card.append(head, fields);
    propsFor(card, type, props);
    library.append(card);
    return card;
  };
  const propsFor = (card, type, props = {}) => {
    const area = card.querySelector('[data-component-props]');
    area.replaceChildren();
    switch (type) {
      case 'brand': area.append(field('شعار کوتاه', 'tagline', props.tagline || '', true)); break;
      case 'button':
        area.append(field('متن دکمه', 'text', props.text || 'تماس با ما'));
        area.append(field('پیوند داخلی', 'href', props.href || '/fa', false, 'ltr'));
        break;
      case 'text': area.append(field('متن', 'text', props.text || '', true)); break;
      case 'contact':
        area.append(field('تلفن', 'phone', props.phone || '', false, 'ltr'));
        area.append(field('ایمیل', 'email', props.email || '', false, 'ltr'));
        area.append(field('نشانی', 'address', props.address || ''));
        break;
      case 'social': area.append(socialFields(props.links || [])); break;
      default: area.append(el('p', { class: 'vz-template-no-props' }, 'این جزء تنظیمات متنی اضافه ندارد.'));
    }
  };
  const updatePartLabel = id => {
    const card = library.querySelector('[data-component-id="' + CSS.escape(id) + '"]');
    const label = card?.querySelector('[data-component-label]')?.value || id;
    root.querySelectorAll('[data-part-item][data-component-id="' + CSS.escape(id) + '"] strong').forEach(node => { node.textContent = label; });
    const title = card?.querySelector('[data-component-title]');
    if (title) title.textContent = label;
    root.querySelectorAll('[data-component-pick] option').forEach(option => { if (option.value === id) option.textContent = label; });
  };
  const addPartItem = (part, id) => {
    if (partIds(part).includes(id) || partIds(part).length >= 8) return;
    const card = library.querySelector('[data-component-id="' + CSS.escape(id) + '"]');
    if (!card) return;
    const label = card.querySelector('[data-component-label]').value || id;
    const item = el('div', { class: 'vz-template-part-item', 'data-part-item': '1', 'data-component-id': id });
    item.append(el('strong', {}, label), el('code', {}, id));
    const actions = el('div', { class: 'vz-template-part-actions' });
    actions.append(
      el('button', { type: 'button', 'data-move': 'up', 'aria-label': 'انتقال به بالا' }, '↑'),
      el('button', { type: 'button', 'data-move': 'down', 'aria-label': 'انتقال به پایین' }, '↓'),
      el('button', { type: 'button', 'data-remove-from-part': '1' }, 'حذف از بخش')
    );
    item.append(actions);
    partList(part).append(item);
    updateCounts();
  };
  const renderComponent = (type, label, props, siteName) => {
    const box = el('div', { class: 'vz-template-preview-component vz-template-component--' + type });
    switch (type) {
      case 'brand':
        box.append(el('strong', {}, siteName));
        if (props.tagline) box.append(el('small', {}, props.tagline));
        break;
      case 'nav': {
        const nav = el('nav');
        ['خانه', 'خدمات', 'تماس'].forEach(name => nav.append(el('span', {}, name)));
        box.append(nav); break;
      }
      case 'button': box.append(el('span', { class: 'vz-template-button' }, props.text || 'دکمه')); break;
      case 'text': box.append(el('p', {}, props.text || '')); break;
      case 'contact':
        [props.phone, props.email, props.address].filter(Boolean).forEach(value => box.append(el('span', {}, value)));
        break;
      case 'social':
        (props.links || []).forEach(link => { if (link.label) box.append(el('span', {}, link.label)); });
        break;
      case 'copyright': box.append(el('small', {}, '© 2026 ' + siteName)); break;
      default: break;
    }
    return box;
  };
  const renderPreview = () => {
    const components = getComponents();
    const templateName = root.querySelector('[data-template-name]').value;
    const headerLayout = root.querySelector('[data-layout="header"]').value;
    const footerLayout = root.querySelector('[data-layout="footer"]').value;
    preview.classList.toggle('is-mobile', previewWidth === 'mobile');
    preview.classList.toggle('is-desktop', previewWidth === 'desktop');
    previewHeader.className = 'vz-template-preview-header vz-template-preview-header--' + headerLayout;
    previewFooter.className = 'vz-template-preview-footer vz-template-preview-footer--' + footerLayout;
    previewHeader.replaceChildren();
    previewFooter.replaceChildren();
    partIds('header').forEach(id => { const c = components[id]; if (c) previewHeader.append(renderComponent(c.type, c.label, c.props, templateName)); });
    partIds('footer').forEach(id => { const c = components[id]; if (c) previewFooter.append(renderComponent(c.type, c.label, c.props, templateName)); });
    previewStatus.textContent = 'پیش‌نمایش محلی قالب «' + templateName + '»؛ هنوز ذخیره یا فعال نشده است.';
  };
  const serialize = () => ({
    name: root.querySelector('[data-template-name]').value.trim(),
    header_layout: root.querySelector('[data-layout="header"]').value,
    footer_layout: root.querySelector('[data-layout="footer"]').value,
    components: getComponents(),
    header: partIds('header'),
    footer: partIds('footer')
  });
  const markDirty = () => { dirty = true; renderPreview(); updateCounts(); };
  root.addEventListener('input', event => {
    if (event.target.matches('[data-component-label]')) updatePartLabel(event.target.closest('[data-component-card]').dataset.componentId);
    markDirty();
  });
  root.addEventListener('change', event => {
    if (event.target.matches('#template-select')) return;
    if (event.target.matches('[data-component-type]')) {
      const card = event.target.closest('[data-component-card]');
      propsFor(card, event.target.value, {});
    }
    markDirty();
  });
  root.addEventListener('click', event => {
    const addComponent = event.target.closest('[data-add-component]');
    if (addComponent) {
      if (cards().length >= 16) { previewStatus.textContent = 'حداکثر ۱۶ جزء در هر قالب مجاز است.'; return; }
      const type = root.querySelector('#new-component-type').value;
      const label = root.querySelector('#new-component-label').value.trim();
      if (!label) { root.querySelector('#new-component-label').focus(); return; }
      const id = idFor();
      addCard(id, type, label, {});
      ['header', 'footer'].forEach(part => {
        const pick = root.querySelector('[data-part-list="' + part + '"]')?.parentElement?.querySelector('[data-component-pick]');
        if (pick) pick.append(el('option', { value: id }, label));
      });
      root.querySelector('#new-component-label').value = '';
      markDirty(); return;
    }
    const addPart = event.target.closest('[data-add-to-part]');
    if (addPart) {
      const part = addPart.dataset.addToPart;
      const select = addPart.parentElement.querySelector('[data-component-pick]');
      if (select.value) addPartItem(part, select.value);
      markDirty(); return;
    }
    const move = event.target.closest('[data-move]');
    if (move) {
      const item = move.closest('[data-part-item]');
      const list = item.parentElement;
      if (move.dataset.move === 'up' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
      if (move.dataset.move === 'down' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
      markDirty(); return;
    }
    const removePart = event.target.closest('[data-remove-from-part]');
    if (removePart) { removePart.closest('[data-part-item]').remove(); markDirty(); return; }
    const removeComponent = event.target.closest('[data-remove-component]');
    if (removeComponent) {
      const card = removeComponent.closest('[data-component-card]');
      const id = card.dataset.componentId;
      if (!window.confirm('این جزء از کتابخانه و همهٔ محل‌های استفاده حذف شود؟')) return;
      root.querySelectorAll('[data-part-item][data-component-id="' + CSS.escape(id) + '"]').forEach(node => node.remove());
      root.querySelectorAll('[data-component-pick] option[value="' + CSS.escape(id) + '"]').forEach(node => node.remove());
      card.remove(); markDirty(); return;
    }
    const previewButton = event.target.closest('[data-preview]');
    if (previewButton) {
      previewWidth = previewButton.dataset.preview;
      root.querySelectorAll('[data-preview]').forEach(button => button.setAttribute('aria-pressed', button === previewButton ? 'true' : 'false'));
      renderPreview(); return;
    }
    if (event.target.closest('[data-delete-template]') && !window.confirm('قالب سفارشی حذف شود؟')) event.preventDefault();
  });
  root.querySelector('#template-select')?.addEventListener('change', event => {
    if (dirty && !window.confirm('تغییرهای ذخیره‌نشده کنار گذاشته شوند؟')) {
      event.target.value = event.target.dataset.current || event.target.value;
      return;
    }
    window.location.assign('/admin/template-builder?template=' + encodeURIComponent(event.target.value));
  });
  form.addEventListener('submit', event => {
    if (form.dataset.conflict === '1') { event.preventDefault(); return; }
    const action = event.submitter?.value || 'save';
    payload.value = JSON.stringify(serialize());
    if (action === 'duplicate' && !root.querySelector('[name="new_template_name"]').value.trim()) {
      event.preventDefault();
      root.querySelector('[name="new_template_name"]').focus();
      return;
    }
  });
  const templateSelect = root.querySelector('#template-select');
  if (templateSelect) templateSelect.dataset.current = templateSelect.value;
  root.querySelectorAll('[data-component-label]').forEach(input => input.addEventListener('input', () => updatePartLabel(input.closest('[data-component-card]').dataset.componentId)));
  updateCounts();
  renderPreview();
})();
