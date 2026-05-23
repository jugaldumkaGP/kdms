function _(el) {
    return document.getElementById(el);
}
var newDevotee ={};

/** Parse KDMS JSON from Logic/requestManager.php or APIs; handle session 401 JSON. */
function kdmsParseAjaxJson(response) {
    try {
        var raw = typeof response === 'string' ? response.trim() : '';
        if (raw === '') {
            alert('Empty response from server.');
            return null;
        }
        var o = JSON.parse(raw);
        if (o && o.ok === false) {
            var msg =
                o.error === 'unauthenticated'
                    ? 'Your session expired or you are not signed in. Please sign in again and retry.'
                    : o.error === 'invalid_service_key'
                    ? 'Service authentication failed.'
                    : o.error === 'forbidden'
                    ? 'You do not have permission for this action.'
                    : (o.message || JSON.stringify(o));
            alert(msg);
            return null;
        }
        return o;
    } catch (e) {
        console.error('KDMS: non-JSON response', e, response);
        alert(
            'Unexpected server response (not valid JSON). If you are logged in, check the browser Network tab.'
        );
        return null;
    }
}

//javascript function for ajax call
function saveFormData(formId, flag) {
    var r =null; // so that we can access it outside .ajax();
    var requestManagerUrl =
        (typeof window.kdmsRequestManagerUrl === 'string' && window.kdmsRequestManagerUrl !== '')
            ? window.kdmsRequestManagerUrl
            : ('/' + directoryName + '/Logic/requestManager.php');
    var webRoot =
        (typeof window.kdmsWebRoot === 'string' && window.kdmsWebRoot !== '')
            ? window.kdmsWebRoot
            : ('/' + directoryName + '/');
    // Check if current devotee's data already saved.
    if (dataSaved) {
        if (!duplicateEntryBlocker(2)) {
            alert("Devotee record already added!"); 
            return false;
        }
        
    } 
    var formData = jQuery(formId).serialize();
    var updateSuccess = false;
    if (validateInput()) {
        jQuery.ajax({
            url: requestManagerUrl,
            type: 'POST',
            data: formData,
            async: false,
            success: function (response) {
                console.log(response);
                r = kdmsParseAjaxJson(response);
                if (r === null) {
                    return;
                }

                if (r['flag'] == true) {
                    updateSuccess = true;
                    dataSaved = true;
                    document.getElementById("devotee_key").value = r['info'];
                    duplicateEntryBlocker(1);
                    window.kdmsDedupSaveNotice = (r.message && String(r.message).trim() !== '') ? r.message : '';
                    if (typeof window.kdmsRefreshDedupHints === 'function') {
                        window.kdmsRefreshDedupHints();
                    }
                } else {
                    alert(typeof r.message !== 'undefined' ? r.message : 'Save failed.');
                    updateSuccess = false;
                }
            },
            error: function (xhr) {
                alert('Save request failed (HTTP ' + xhr.status + ').');
            },
        });
        //Save and stay on the record
        if (flag == 1 && updateSuccess) {
            var saveMsg = window.kdmsDedupSaveNotice || 'Devotee record updated successfully!';
            alert(saveMsg);
            window.location.assign(webRoot + 'UI/addDevoteeI.php?devotee_key=' + encodeURIComponent(r['info']));
        }
        var check =false;
        if(flag== -2){
            var pcnt=parseInt($('.btn-sgc').attr('data-pcount'));
            var timet='times';
            if (pcnt<2){
                timet='time';
            }
             check=confirm("Card already printed "+pcnt+" "+timet+" for this Devotee!. Do you still want to print");                        
        }
        if(check){
              var flag = -1;
        }
        //save and Print
        if (flag == -1 && updateSuccess) {
            console.log("calling ajax to add devotee card. ;");   
            console.log('devotee_key' + r['info'] + 'requestType'+ "addToPrintQueue");
            $.ajax({
                url: requestManagerUrl,
                type: 'POST',
                //data: {'devotee_key': document.getElementById("devotee_key").value, 'requestType': "addToPrintQueue"},
                data: {'devotee_key': r['info'], 'requestType': "addToPrintQueue"},
                async: false,
                success: function (response) {
                    var r2 = kdmsParseAjaxJson(response);
                    if (r2 === null) {
                        updateSuccess = false;
                        return;
                    }
                    if (r2['flag'] == true) {
                        alert("Devotee Record updated and card added to Print Queue!");
                        window.location.assign(
                            webRoot + 'UI/addDevoteeI.php?devotee_key=' + encodeURIComponent(r2['info'])
                        );
                    } else {
                        alert(typeof r2.message !== 'undefined' ? r2.message : 'Print queue update failed.');
                        updateSuccess = false;
                    }
                },
                error: function (xhr) {
                    alert('Print queue request failed (HTTP ' + xhr.status + ').');
                    updateSuccess = false;
                },
            });
        }
        //save and exit
        if (flag == 0 && updateSuccess) {
            alert(window.kdmsDedupSaveNotice || 'Devotee record updated successfully!');
            window.location.assign(webRoot + 'UI/index.php');
        }
    }
}

function duplicateEntryBlocker(step) {
        var first_name = jQuery('#devotee_first_name').val();
        var last_name = jQuery('#devotee_last_name').val();
        var dob = jQuery('#devotee_dob').val();
        var id_number = jQuery('#devotee_id_number').val();
        var phone_number = jQuery('#devotee_cell_phone_number').val();
        var station = jQuery('#devotee_station').val();
        
    if (step == 1) {
        newDevotee.first_name = first_name;
        newDevotee.last_name = last_name;
        newDevotee.dob = dob;
        newDevotee.id_number = id_number;
        newDevotee.phone_number = phone_number;
        newDevotee.station = station;
    } else if (step == 2) {
        if (newDevotee.first_name == first_name && newDevotee.last_name == last_name && newDevotee.dob == dob
              && newDevotee.id_number == id_number && newDevotee.phone_number == phone_number && newDevotee.station == station) {
              return false; 
        }
    }
    return true;
}
function validateInput() {
    var response = true;
    var message = "";
    if (document.getElementById("devotee_first_name").value == "") {
        message = "Devotee first name is missing.\n";
        response = false;
    }

    if (document.getElementById("devotee_last_name").value == "") {
        message = message + "Devotee last name is missing. \n";
        response = false;
    }

    if (document.getElementById("devotee_email").value != "") {                        
        if (!validateEmail(document.getElementById("devotee_email").value)) {
            message = message + "Email is invalid.\n";
            response = false;
        }
    }

    var dobEl = document.getElementById("devotee_dob");
    if (dobEl && dobEl.value !== "") {
        if (!validateDate(dobEl.value)) {
            message = message + "Date of birth is invalid.\n";
            response = false;
        }
    }

    var phoneEl = document.getElementById("devotee_cell_phone_number");
    if (phoneEl && phoneEl.value.trim() !== "") {
        var phoneDigits = phoneEl.value.replace(/\D+/g, "");
        if (phoneDigits.length > 12 && phoneDigits.indexOf("91") === 0) {
            phoneDigits = phoneDigits.substring(2);
        }
        if (phoneDigits.length > 11 && phoneDigits.indexOf("0") === 0) {
            phoneDigits = phoneDigits.substring(1);
        }
        if (phoneDigits.length > 10) {
            message = message + "Phone number must be at most 10 digits.\n";
            response = false;
        } else if (phoneDigits.length > 0) {
            phoneEl.value = phoneDigits;
        }
    }

    if (!response) {
        alert(message);
    }

    return response;
}

function validateEmail(email) {
    var re = /\S+@\S+\.\S+/;
    
    return re.test(email);
}

/** Accept yyyy-mm-dd, dd-mm-yyyy, or dd/mm/yyyy; return ISO yyyy-mm-dd or empty. */
function normalizeDateInput(raw) {
    raw = (raw || '').trim();
    if (!raw) {
        return '';
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        return raw;
    }
    var dmy = raw.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
    if (dmy) {
        var day = parseInt(dmy[1], 10);
        var month = parseInt(dmy[2], 10);
        var year = parseInt(dmy[3], 10);
        var dt = new Date(Date.UTC(year, month - 1, day));
        if (
            dt.getUTCFullYear() === year &&
            dt.getUTCMonth() === month - 1 &&
            dt.getUTCDate() === day
        ) {
            return dt.toISOString().slice(0, 10);
        }
    }
    return '';
}

function validateDate(isoDate) {
    var normalized = normalizeDateInput(isoDate);
    if (!normalized) {
        return false;
    }
    if (normalized !== isoDate && isoDate.indexOf('-') !== 4) {
        isoDate = normalized;
    }
    if (isNaN(Date.parse(isoDate))) {
        return false;
    }
    return isoDate === new Date(isoDate).toISOString().substr(0, 10);
}
