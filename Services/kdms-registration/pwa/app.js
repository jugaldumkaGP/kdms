(function () {
  'use strict';

  const HIGH = 0.7;
  const MED = 0.4;
  const ID_HINTS = {
    Aadhaar: '12-digit number',
    'PAN Card': 'e.g. ABCDE1234F',
    Passport: 'e.g. A1234567',
    'Voter ID': '3 letters + 7 digits',
    'Driving License': 'State format varies',
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

  const $ = (sel) => document.querySelector(sel);
  const form = $('#reg-form');
  const spinner = $('#spinner');
  const dobPicker = form.elements.Devotee_DOB;

  function showSpinner(on) {
    spinner.hidden = !on;
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

  function applyOcrField(name, field) {
    const input = form.elements.namedItem(name);
    if (!input || !field) return;
    const conf = Number(field.confidence) || 0;
    const val = field.value;
    if (conf >= MED && val) {
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

  $('#btn-scan').addEventListener('click', () => $('#id-file').click());

  $('#id-file').addEventListener('change', async (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    const status = $('#scan-status');
    status.hidden = false;
    status.textContent = 'Reading ID…';
    showSpinner(true);
    try {
      const fd = new FormData();
      fd.append('id_image', file);
      fd.append('Devotee_Key', reservedDevoteeKey);
      const res = await fetch('/api/ocr-extract', { method: 'POST', body: fd });
      const data = await res.json();
      idGcsPath = data.id_gcs_path || '';
      OCR_FIELDS.forEach((name) => applyOcrField(name, data[name]));
      status.textContent = 'ID scanned. Please check and complete the form below.';
    } catch (err) {
      status.textContent = 'Could not read ID. Please enter details manually.';
    } finally {
      showSpinner(false);
      e.target.value = '';
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
