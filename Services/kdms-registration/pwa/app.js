(function () {
  'use strict';

  const HIGH = 0.7;
  const MED = 0.4;
  const ID_PLACEHOLDER = '/pwa/assets/id-placeholder.png';
  const ID_HINTS = {
    Aadhaar: '12-digit number',
    PAN: 'e.g. ABCDE1234F',
    Passport: 'e.g. A1234567',
    'Voter ID': '3 letters + 7 digits',
    DL: 'State format varies',
    Other: ''
  };

  const TITLE_CASE_FIELDS = [
    'Devotee_First_Name',
    'Devotee_Last_Name',
    'Devotee_Address_1',
    'Devotee_Address_2',
    'Devotee_Station',
    'Devotee_State'
  ];

  const OCR_FIELDS = [
    'Devotee_First_Name',
    'Devotee_Last_Name',
    'Devotee_ID_Number',
    'Devotee_DOB',
    'Devotee_Gender',
    'Devotee_Email',
    'Devotee_Address_1',
    'Devotee_Address_2',
    'Devotee_Station',
    'Devotee_State',
    'Devotee_Zip'
  ];

  let csrfToken = '';
  let reservedDevoteeKey = '';
  let idGcsPath = '';
  let selfieGcsPath = '';
  let idScanAttempted = false;
  let idScanLocked = false;
  let idPreviewObjectUrl = '';
  let pickingIdFile = false;
  let prasadOnly = false;

  const $ = (sel) => document.querySelector(sel);
  const form = $('#reg-form');
  const spinner = $('#spinner');
  const dobPicker = form ? form.elements.Devotee_DOB : null;
  const REFERRAL_MAX_LEN = 50;

  /**
   * Optional query param ?r= — pre-fills and locks Devotee_Referral (e.g. QR / staff link).
   */
  function initReferralFromUrlParam() {
    if (!form) {
      return;
    }
    const referralInput = form.elements.Devotee_Referral;
    if (!referralInput) {
      return;
    }

    let raw = '';
    try {
      raw = new URLSearchParams(window.location.search).get('r') || '';
    } catch (e) {
      raw = '';
    }

    let decoded = '';
    if (raw !== '') {
      try {
        decoded = decodeURIComponent(raw.replace(/\+/g, ' '));
      } catch (e) {
        decoded = raw.replace(/\+/g, ' ');
      }
    }
    decoded = decoded.trim();
    if (decoded.length > REFERRAL_MAX_LEN) {
      decoded = decoded.substring(0, REFERRAL_MAX_LEN);
    }

    if (decoded !== '') {
      referralInput.value = decoded;
      referralInput.readOnly = true;
      referralInput.classList.add('is-locked');
      referralInput.setAttribute('aria-readonly', 'true');
      return;
    }

    referralInput.readOnly = false;
    referralInput.classList.remove('is-locked');
    referralInput.removeAttribute('aria-readonly');
  }

  initReferralFromUrlParam();

  /**
   * Optional query param ?PO= — marks registration as Prasad Only.
   * Accepts any casing: PO=True, po=true, PO=1, PO=yes, etc.
   */
  function initPrasadOnlyFromUrlParam() {
    try {
      const raw = new URLSearchParams(window.location.search).get('PO')
        || new URLSearchParams(window.location.search).get('po')
        || '';
      const truthy = ['true', '1', 'yes'];
      prasadOnly = truthy.includes(raw.trim().toLowerCase());
    } catch (e) {
      prasadOnly = false;
    }
  }

  initPrasadOnlyFromUrlParam();

  function showSpinner(on) {
    spinner.hidden = !on;
  }

  function setFormLocked(locked) {
    if (!form) return;
    form.classList.toggle('form-locked', locked);
  }

  function setIdScanLocked(locked) {
    idScanLocked = locked;
    const box = $('#btn-id-scan');
    if (box) {
      box.classList.toggle('is-locked', locked);
      box.setAttribute('aria-disabled', locked ? 'true' : 'false');
    }
  }

  function markIdScanAttempted() {
    if (idScanAttempted) return;
    idScanAttempted = true;
    setFormLocked(false);
  }

  function revokeIdPreviewUrl() {
    if (idPreviewObjectUrl) {
      URL.revokeObjectURL(idPreviewObjectUrl);
      idPreviewObjectUrl = '';
    }
  }

  function showIdPreview(src) {
    const img = $('#id-preview');
    if (!img) return;
    revokeIdPreviewUrl();
    img.src = src;
    if (src.startsWith('blob:')) {
      idPreviewObjectUrl = src;
    }
  }

  function clearOcrFields() {
    OCR_FIELDS.forEach((name) => {
      const input = form.elements.namedItem(name);
      if (!input) return;
      input.value = '';
      input.classList.remove('conf-high', 'conf-med');
      const verify = input.parentElement && input.parentElement.querySelector('.verify');
      if (verify) verify.remove();
    });
  }

  function resetIdScanUi() {
    revokeIdPreviewUrl();
    showIdPreview(ID_PLACEHOLDER);
    idGcsPath = '';
    setIdScanLocked(false);
    const cancelBtn = $('#btn-id-cancel');
    if (cancelBtn) cancelBtn.hidden = true;
    const status = $('#scan-status');
    if (status) status.hidden = true;
  }

  function toTitleCase(str) {
    if (!str) return '';
    return str
      .toLowerCase()
      .replace(/\b([a-zà-ÿ])/g, (m) => m.toUpperCase());
  }

  async function loadCsrf() {
    const res = await fetch('/api/csrf-token');
    const data = await res.json();
    csrfToken = data.token || '';
    const keyRes = await fetch('/api/reserve-devotee-key');
    const keyData = await keyRes.json();
    reservedDevoteeKey = (keyData.Devotee_Key || '').trim();
    if (!reservedDevoteeKey) {
      throw new Error('Could not reserve devotee key');
    }
  }

  function setFieldConfidence(input, confidence) {
    input.classList.remove('conf-high', 'conf-med');
    const verify = input.parentElement.querySelector('.verify');
    if (verify) verify.remove();
    if (confidence >= HIGH) {
      input.classList.add('conf-high');
    } else if (confidence >= MED) {
      input.classList.add('conf-med');
      const s = document.createElement('small');
      s.className = 'verify';
      s.textContent = 'Please verify';
      input.after(s);
    }
  }

  function dobToIso(raw) {
    raw = (raw || '').trim();
    if (!raw) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    const dmy = raw.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
    if (dmy) {
      return `${dmy[3]}-${dmy[2].padStart(2, '0')}-${dmy[1].padStart(2, '0')}`;
    }
    return raw;
  }

  function applyOcrField(name, field) {
    const input = form.elements.namedItem(name);
    if (!input || !field) return;
    const conf = Number(field.confidence) || 0;
    let val = field.value;
    if (conf >= MED && val) {
      if (name === 'Devotee_DOB') {
        val = dobToIso(String(val));
      }
      input.value = val;
      setFieldConfidence(input, conf);
    }
  }

  TITLE_CASE_FIELDS.forEach((name) => {
    const el = form.elements.namedItem(name);
    if (!el) return;
    el.addEventListener('blur', () => {
      if (el.value.trim()) {
        el.value = toTitleCase(el.value.trim());
      }
    });
  });

  $('#btn-id-scan').addEventListener('click', () => {
    if (idScanLocked) return;
    $('#id-file').click();
  });

  $('#id-file').addEventListener('click', () => {
    pickingIdFile = true;
  });

  window.addEventListener('focus', () => {
    if (!pickingIdFile) return;
    setTimeout(() => {
      if (!pickingIdFile) return;
      const fileInput = $('#id-file');
      const file = fileInput.files && fileInput.files[0];
      if (file) {
        pickingIdFile = false;
        return;
      }
      pickingIdFile = false;
      markIdScanAttempted();
      const status = $('#scan-status');
      status.hidden = false;
      status.textContent = 'You can fill in the form manually below.';
    }, 600);
  });

  $('#id-file').addEventListener('change', async (e) => {
    pickingIdFile = false;
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    const status = $('#scan-status');
    status.hidden = false;
    status.textContent = 'Reading ID…';
    showSpinner(true);

    let previewUrl = '';
    try {
      previewUrl = URL.createObjectURL(file);
      showIdPreview(previewUrl);

      const fd = new FormData();
      fd.append('id_image', file);
      fd.append('Devotee_Key', reservedDevoteeKey);
      const res = await fetch('/api/ocr-extract', { method: 'POST', body: fd });
      const data = await res.json();
      idGcsPath = data.id_gcs_path || '';
      if (!res.ok) {
        throw new Error(data.error || 'Could not read ID');
      }
      OCR_FIELDS.forEach((name) => applyOcrField(name, data[name]));
      status.textContent = 'ID scanned. Please check and complete the form below.';
    } catch (err) {
      status.textContent = 'Could not read ID. Please enter details manually.';
    } finally {
      markIdScanAttempted();
      if (idGcsPath) {
        setIdScanLocked(true);
        $('#btn-id-cancel').hidden = false;
      }
      showSpinner(false);
      e.target.value = '';
    }
  });

  $('#btn-id-cancel').addEventListener('click', async () => {
    const pathToDelete = idGcsPath;
    const cancelBtn = $('#btn-id-cancel');
    cancelBtn.disabled = true;
    showSpinner(true);
    try {
      if (pathToDelete) {
        const res = await fetch('/api/id-scan-cancel', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({
            Devotee_Key: reservedDevoteeKey,
            id_gcs_path: pathToDelete,
            csrf_token: csrfToken
          })
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Could not remove ID image');
        }
      }
      clearOcrFields();
      resetIdScanUi();
    } catch (err) {
      alert('Could not cancel ID scan. Please try again.');
    } finally {
      cancelBtn.disabled = false;
      showSpinner(false);
    }
  });

  form.elements.Devotee_ID_Type.addEventListener('change', () => {
    const t = form.elements.Devotee_ID_Type.value;
    $('#id-hint').textContent = ID_HINTS[t] || '';
  });

  $('#btn-selfie').addEventListener('click', () => $('#selfie-file').click());

  $('#selfie-file').addEventListener('change', async (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    showSpinner(true);
    try {
      const urlRes = await fetch('/api/selfie-upload-url?Devotee_Key=' + encodeURIComponent(reservedDevoteeKey));
      const urlData = await urlRes.json();
      if (!urlRes.ok || !urlData.upload_url) {
        throw new Error(urlData.error || 'Could not prepare photo upload');
      }
      const put = await fetch(urlData.upload_url, {
        method: 'PUT',
        headers: { 'Content-Type': 'image/jpeg' },
        body: file
      });
      if (!put.ok) throw new Error('upload failed');
      selfieGcsPath = urlData.selfie_gcs_path || '';
      const preview = $('#selfie-preview');
      preview.src = URL.createObjectURL(file);
      preview.hidden = false;
    } catch (err) {
      const detail = err && err.message ? String(err.message) : '';
      alert(detail
        ? 'Photo upload failed: ' + detail + '. You can still register without a photo.'
        : 'Photo upload failed. You can still register without a photo.');
    } finally {
      showSpinner(false);
      e.target.value = '';
    }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    $('#form-error').hidden = true;
    if (!idScanAttempted) {
      $('#scan-status').hidden = false;
      $('#scan-status').textContent = 'Please scan your ID card to start.';
      $('#btn-id-scan').focus();
      return;
    }
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    const btn = $('#btn-submit');
    btn.disabled = true;
    showSpinner(true);

    TITLE_CASE_FIELDS.forEach((name) => {
      const el = form.elements.namedItem(name);
      if (el && el.value.trim()) {
        el.value = toTitleCase(el.value.trim());
      }
    });

    const payload = {
      Devotee_Key: reservedDevoteeKey,
      Devotee_First_Name: form.elements.Devotee_First_Name.value.trim(),
      Devotee_Last_Name: form.elements.Devotee_Last_Name.value.trim(),
      Devotee_Gender: form.elements.Devotee_Gender.value,
      Devotee_DOB: (form.elements.Devotee_DOB.value || '').trim(),
      Devotee_ID_Type: form.elements.Devotee_ID_Type.value,
      Devotee_ID_Number: form.elements.Devotee_ID_Number.value.trim(),
      Devotee_Cell_Phone_Number: form.elements.Devotee_Cell_Phone_Number.value.trim(),
      Devotee_Email: form.elements.Devotee_Email.value.trim(),
      Devotee_Referral: form.elements.Devotee_Referral.value.trim(),
      Devotee_Address_1: form.elements.Devotee_Address_1.value.trim(),
      Devotee_Address_2: form.elements.Devotee_Address_2.value.trim(),
      Devotee_Station: form.elements.Devotee_Station.value.trim(),
      Devotee_State: form.elements.Devotee_State.value.trim(),
      Devotee_Zip: form.elements.Devotee_Zip.value.trim(),
      id_gcs_path: idGcsPath,
      selfie_gcs_path: selfieGcsPath,
      prasad_only: prasadOnly,
      csrf_token: csrfToken
    };
    try {
      const res = await fetch('/api/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (data.success && data.Devotee_Key) {
        $('#scan-section').hidden = true;
        form.hidden = true;
        $('#success-screen').hidden = false;
        $('#ref-key').textContent = data.Devotee_Key;
        return;
      }
      if (res.status === 429) {
        showError(data.error || 'Too many requests. Please wait.');
        return;
      }
      showError(data.error || 'Registration failed. Please try again.');
    } catch (err) {
      showError('Network error. Please check your connection and try again.');
    } finally {
      btn.disabled = false;
      showSpinner(false);
    }
  });

  function showError(msg) {
    $('#error-message').textContent = msg;
    $('#error-screen').hidden = false;
    form.hidden = true;
    $('#scan-section').hidden = true;
  }

  $('#btn-retry').addEventListener('click', () => {
    $('#error-screen').hidden = true;
    form.hidden = false;
    $('#scan-section').hidden = false;
  });

  loadCsrf().catch(() => {});
})();
