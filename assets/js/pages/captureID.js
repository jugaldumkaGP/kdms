(function () {
    'use strict';

    const HIGH = 0.7;
    const MED = 0.4;

    const FIELD_MAP = {
        Devotee_First_Name: 'devotee_first_name',
        Devotee_Last_Name: 'devotee_last_name',
        Devotee_ID_Number: 'devotee_id_number',
        Devotee_DOB: 'devotee_dob',
        Devotee_Gender: 'devotee_gender',
        Devotee_Email: 'devotee_email',
        Devotee_Address_1: 'devotee_address_1',
        Devotee_Address_2: 'devotee_address_2',
        Devotee_Station: 'devotee_station',
        Devotee_State: 'devotee_state',
        Devotee_Zip: 'devotee_zip'
    };

    const TITLE_CASE_IDS = [
        'devotee_first_name',
        'devotee_last_name',
        'devotee_address_1',
        'devotee_address_2',
        'devotee_station',
        'devotee_state'
    ];

    const OCR_FIELDS = Object.keys(FIELD_MAP);

    function getBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = () => resolve(reader.result);
            reader.onerror = (error) => reject(error);
        });
    }

    function resolveReservedKey() {
        const modal = document.getElementById('devotee_key_modal');
        const main = document.getElementById('devotee_key');
        let key = (modal && modal.value) ? modal.value.trim() : '';
        if (key === '' && main && main.value) {
            key = main.value.trim();
            if (modal) {
                modal.value = key;
            }
        }
        return key;
    }

    function toTitleCase(str) {
        if (!str) return '';
        return str.toLowerCase().replace(/\b([a-zà-ÿ])/g, (m) => m.toUpperCase());
    }

    function normalizeDobForStaff(val) {
        val = (val || '').trim();
        if (!val) return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(val)) {
            const p = val.split('-');
            return p[2] + '-' + p[1] + '-' + p[0];
        }
        const m = val.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/);
        if (m) {
            return m[1].padStart(2, '0') + '-' + m[2].padStart(2, '0') + '-' + m[3];
        }
        return val;
    }

    function setFieldConfidence(input, confidence) {
        if (!input || !input.parentElement) return;
        input.style.borderLeft = '';
        const hint = input.parentElement.querySelector('.ocr-verify-hint');
        if (hint) hint.remove();
        if (confidence >= HIGH) {
            input.style.borderLeft = '4px solid #4caf50';
        } else if (confidence >= MED) {
            input.style.borderLeft = '4px solid #ff9800';
            const s = document.createElement('small');
            s.className = 'ocr-verify-hint text-warning d-block';
            s.textContent = 'Please verify';
            input.parentElement.appendChild(s);
        }
    }

    function applyOcrField(ocrName, field) {
        const elId = FIELD_MAP[ocrName];
        if (!elId) return;
        const input = document.getElementById(elId);
        if (!input || !field) return;
        const conf = Number(field.confidence) || 0;
        let val = field.value != null ? String(field.value) : '';
        if (conf < MED || !val) return;
        if (ocrName === 'Devotee_DOB') {
            val = normalizeDobForStaff(val);
        }
        if (ocrName === 'Devotee_Gender') {
            const g = val.toUpperCase().charAt(0);
            val = g === 'F' ? 'F' : g === 'M' ? 'M' : '';
            if (!val) return;
        }
        input.value = val;
        if (TITLE_CASE_IDS.indexOf(elId) >= 0 && val) {
            input.value = toTitleCase(input.value.trim());
        }
        setFieldConfidence(input, conf);
    }

    function applyStaffOcrResponse(data) {
        if (!data || typeof data !== 'object') return;
        OCR_FIELDS.forEach((name) => applyOcrField(name, data[name]));
    }

    async function runStaffOcrPrefill(file) {
        const devoteeKey = resolveReservedKey().toUpperCase();
        if (!devoteeKey || !/^P[0-9A-Z]+$/.test(devoteeKey)) {
            return;
        }
        const ocrUrl = (window.kdmsWebRoot || '/').replace(/\/?$/, '/') + 'Logic/staffOcrExtractProxy.php';
        const fd = new FormData();
        fd.append('id_image', file);
        fd.append('Devotee_Key', devoteeKey);
        try {
            const res = await fetch(ocrUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (res.ok || res.status === 200) {
                applyStaffOcrResponse(data);
            }
        } catch (e) {
            /* OCR failure is non-fatal; upload still proceeds */
        }
    }

    function uploadIDImage(base64_image_data, ocrRan) {
        const devoteeID = resolveReservedKey();
        if (devoteeID === '') {
            alert('Devotee ID is not reserved yet. Refresh the Add Devotee page and try again.');
            return;
        }
        $.ajax({
            url: '../api/managePhoto.php',
            method: 'POST',
            data: { image: base64_image_data, api_type: 4, devotee_key: devoteeID }
        }).done(function (resp) {
            var parsed = typeof resp === 'string' ? JSON.parse(resp) : resp;
            if (!parsed || parsed.status !== true) {
                alert(parsed && parsed.message ? parsed.message : 'ID image upload failed.');
                return;
            }
            const msg = ocrRan
                ? 'ID image saved for ' + devoteeID + '. Form fields were prefilled where detected — please verify.'
                : 'ID image saved for key ' + devoteeID;
            alert(msg);
            const photo_id_preview_div = document.getElementById('photo-id-preview_div');
            const preview_image = `<img class="photo-id-preview" src="${base64_image_data}" alt="devotee ID" height="400px" width="200px"></img>`;
            photo_id_preview_div.innerHTML = preview_image;
        }).fail(function () {
            alert('ID image upload request failed.');
        });
    }

    document.getElementById('cameraIDFileInput').addEventListener('change', async function () {
        const file = this.files && this.files[0];
        this.value = '';
        if (!file) return;

        let ocrRan = false;
        try {
            await runStaffOcrPrefill(file);
            ocrRan = true;
        } catch (e) {
            ocrRan = false;
        }

        try {
            const base64_image_data = await getBase64(file);
            uploadIDImage(base64_image_data, ocrRan);
        } catch (e) {
            alert('Could not read the selected image.');
        }
    });

    function uploadDevoteeImage(base64_image_data) {
        const devoteeID = resolveReservedKey();
        if (devoteeID === '') {
            alert('Devotee ID is not reserved yet. Refresh the Add Devotee page and try again.');
            return;
        }
        $.ajax({
            url: '../api/managePhoto.php',
            method: 'POST',
            data: { image: base64_image_data, api_type: 3, devotee_key: devoteeID }
        }).done(function (resp) {
            var parsed = typeof resp === 'string' ? JSON.parse(resp) : resp;
            if (!parsed || parsed.status !== true) {
                alert(parsed && parsed.message ? parsed.message : 'Photo upload failed.');
                return;
            }
            alert('Devotee image saved for key ' + devoteeID);
            const photo_mobile_preview_div = document.getElementById('photo-mobile-preview_div');
            const preview_image = `<img class="devoteeImage" id="devoteeImage" src="${base64_image_data}" alt="devotee image"></img>`;
            photo_mobile_preview_div.innerHTML = preview_image;
        }).fail(function () {
            alert('Photo upload request failed.');
        });
    }

    document.getElementById('cameraMobilePhotoFileInput').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        getBase64(file).then((base64_image_data) => uploadDevoteeImage(base64_image_data));
    });
})();
