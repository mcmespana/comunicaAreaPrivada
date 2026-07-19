/*
* Place in this file any custom js you want to use in your plugin
*/
jQuery(document).ready(function($){
    // Run selectize. dropdownParent: 'body' evita que el desplegable quede
    // por debajo de las tarjetas siguientes del formulario.
    $('select[multiple]').selectize({ dropdownParent: 'body' });
    // El mensaje de éxito/error se despide solo tras unos segundos (antes se
    // esfumaba al primer clic o tecla, y era fácil no llegar a leerlo).
    var msg = document.getElementById('successMsg');
    if (msg) {
        setTimeout(function () { msg.classList.add('stic-msg-hide'); }, 7000);
        msg.addEventListener('transitionend', function () {
            if (msg.classList.contains('stic-msg-hide')) { msg.style.display = 'none'; }
        });
    }
});

/* ---- Errores de validación inline (sustituyen a los alert() nativos) ---- */
function sticSetFieldError(el, message) {
    if (!el) { window.alert(message); return; }
    el.setAttribute('invalid', '');
    el.setAttribute('aria-invalid', 'true');
    var holder = el.closest ? (el.closest('li') || el.parentElement) : el.parentElement;
    var note = holder ? holder.querySelector('.stic-field-error') : null;
    if (!note) {
        note = document.createElement('small');
        note.className = 'stic-field-error';
        note.setAttribute('role', 'alert');
        note.id = (el.id || 'campo') + '-error';
        el.setAttribute('aria-describedby', note.id);
        if (holder) { holder.appendChild(note); }
    }
    note.textContent = message;
    try { el.focus(); el.scrollIntoView({ block: 'center' }); } catch (err) { el.focus(); }
}

function sticClearFieldError(el) {
    if (!el) { return; }
    el.removeAttribute('invalid');
    el.removeAttribute('aria-invalid');
    var holder = el.closest ? (el.closest('li') || el.parentElement) : el.parentElement;
    var note = holder ? holder.querySelector('.stic-field-error') : null;
    if (note) { note.remove(); }
}

function alerta(obj) {
    var lastName = '';
    obj.style.color = "red";
    var confirmMsg = (typeof stic_script_vars !== 'undefined' && stic_script_vars.changeConfirmation)
        ? stic_script_vars.changeConfirmation
        : '¿Está seguro de que desea cambiar este valor?';
    result = window.confirm(confirmMsg);
    if (result) {
        lastName = obj.value;
    }
    else {
        obj.value = lastName;
    }
}

function formatDateTimeLocal() {
    if (typeof jQuery !== 'undefined') {
        jQuery("input[type=datetime-local]").each(function(){
            if (this.value){
                datetime = this.value;
                datetime = datetime.replace("T", " ");
                jQuery(this).clone().appendTo('#stic-wp-pa').removeAttr('required').attr('type', 'text').val(datetime+':00').hide();
                jQuery(this).attr('name', this+'_clone').attr('id', this+'_clone');
            }
        });
    }
}

function confirmDelete(obj) {
    var v = (typeof stic_script_vars !== 'undefined') ? stic_script_vars : {};
    var msg    = v.deleteConfirmation || '¿Está seguro de que desea eliminar este registro?';
    var title  = v.deleteTitle        || 'Eliminar registro';
    var cancel = v.deleteCancel       || 'Cancelar';
    var confirm = v.deleteConfirmBtn  || 'Eliminar';

    var form = (obj && obj.form) ? obj.form : document.getElementById('stic-wp-pa');

    _sticDeleteModal(title, msg, cancel, confirm, function () {
        var actionInput = document.getElementById('stic-action');
        if (actionInput) actionInput.value = 'delete';
        if (form) form.submit();
    });

    return false; // always prevent default — the modal handles submission
}

/**
 * Render and manage the custom delete-confirmation modal.
 */
function _sticDeleteModal(title, message, cancelLabel, confirmLabel, onConfirm) {
    // Remove any existing modal
    var old = document.getElementById('stic-delete-modal');
    if (old) old.remove();

    // Remember the element that opened the modal so focus can be restored on close.
    var trigger = document.activeElement;

    var overlay = document.createElement('div');
    overlay.id = 'stic-delete-modal';
    overlay.className = 'stic-modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'stic-modal-title');
    overlay.setAttribute('aria-describedby', 'stic-modal-msg');
    overlay.innerHTML =
        '<div class="stic-modal-card">' +
            '<div class="stic-modal-icon">' +
                '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
                    '<path d="M3 6h18"/>' +
                    '<path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>' +
                    '<path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>' +
                    '<line x1="10" y1="11" x2="10" y2="17"/>' +
                    '<line x1="14" y1="11" x2="14" y2="17"/>' +
                '</svg>' +
            '</div>' +
            '<h4 id="stic-modal-title" class="stic-modal-title">' + title + '</h4>' +
            '<p id="stic-modal-msg" class="stic-modal-msg">' + message + '</p>' +
            '<div class="stic-modal-actions">' +
                '<button type="button" class="stic-modal-btn stic-modal-btn--cancel">' + cancelLabel + '</button>' +
                '<button type="button" class="stic-modal-btn stic-modal-btn--delete">' +
                    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>' +
                    confirmLabel +
                '</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);

    // Force reflow, then activate (triggers CSS transition)
    overlay.offsetHeight; // eslint-disable-line no-unused-expressions
    overlay.classList.add('is-active');

    // Move focus into the dialog (the non-destructive action gets it first).
    var cancelBtn = overlay.querySelector('.stic-modal-btn--cancel');
    if (cancelBtn) cancelBtn.focus();

    // --- helpers ---
    function close() {
        overlay.classList.remove('is-active');
        setTimeout(function () { overlay.remove(); }, 280);
        document.removeEventListener('keydown', escHandler);
        // Return focus to whatever opened the modal.
        if (trigger && typeof trigger.focus === 'function') trigger.focus();
    }
    function escHandler(e) { if (e.key === 'Escape') close(); }

    // Trap Tab focus inside the dialog.
    overlay.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') return;
        var focusables = overlay.querySelectorAll('button');
        if (!focusables.length) return;
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    // --- events ---
    overlay.querySelector('.stic-modal-btn--cancel').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', escHandler);

    overlay.querySelector('.stic-modal-btn--delete').addEventListener('click', function () {
        close();
        if (onConfirm) onConfirm();
    });
}

function verifyIban(obj) {
    let paymentMethod = $('#payment_method').val();
    if (paymentMethod == 'direct_debit') {
        // Delete non alphanumeric characters and transform to uppercase
        obj.value = obj.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();

        result = IBAN.isValid(obj.value);
        if (!result) {
            sticSetFieldError(obj, stic_script_vars.wrongIban);
        }
        else {
            sticClearFieldError(obj);
        }
    }
}

function verifyFormIsValid() {
    let elements = document.querySelectorAll("[invalid]");
    if (typeof elements != "undefined" && elements != null && elements.length != null
    && elements.length > 0) {
        // Llevar al usuario al primer campo con error (su mensaje inline ya está pintado).
        sticSetFieldError(elements[0], stic_script_vars.invalidElements);
        return false;
    }
    formatDateTimeLocal();
    return true;
}

function verifyProfileFormIsValid() {
    if (validateId()) {
        return true;
    }
    return false;
}

function handlePaymentMethod(obj) {
    let paymentMethod = $('#payment_method').val();
    if (paymentMethod == 'direct_debit') {
        $('#bank_account').parent().parent().show();
        $('#bank_account').attr("required", "true");

    } else {
        $('#bank_account').parent().parent().hide();
        $('#bank_account').removeAttr("required");
        $('#bank_account').removeAttr("invalid");
        $('#bank_account').val("");
    }
}

function getCurrentDateTime() {
    var date = new Date();
    return String(date.getUTCFullYear()) + "-" + String(date.getUTCMonth() + 1).padStart(2,"0") + "-" + String(date.getUTCDate()).padStart(2,"0") + " " + 
        String(date.getUTCHours()).padStart(2,"0") + ":" + String(date.getUTCMinutes()).padStart(2,"0") + ":" + String(date.getUTCSeconds()).padStart(2,"0");
}

function getCurrentDate() {
    var date = new Date();
    return String(date.getFullYear()) + "-" + String(date.getMonth() + 1).padStart(2,"0") + "-" + String(date.getDate()).padStart(2,"0");
}

function enableDownload(elem) {
    $("#download").val(true);
}

function disableDownload(elem) {
    $("#download").val(false);
}

/**
* Comprueba el campo stic_identification_number_c
*/
function validateId (obj) {
	var idEl = document.getElementById('stic_identification_number_c');
	var tipoIdEl = document.getElementById('stic_identification_type_c');
	var tipoId = 'nif';	// Indica si el type de documento es un NIF o NIE (puede ser passport)
	if (! tipoIdEl) {
		console.warn("No se ha definido el campo Tipo de Identificación, se asume que es un NIF.");
	} else {
  	if(tipoIdEl.options) { 
  		tipoId = tipoIdEl.options[tipoIdEl.selectedIndex];
		} else {
  		tipoId = tipoIdEl;
		} 
  }
    let validDocument = false;
	switch (tipoId.value) {
		case 'nif': 
		case 'nie': validDocument =  isValidDNI(idEl.value); break;
		case 'cif': validDocument = isValidCif(idEl.value); break;
		default: console.log(stic_script_vars.otherIdentificationType);//console.log("Tipo de identificación no validable.");
        validDocument = true;
    }
    if (!validDocument) {
        sticSetFieldError(idEl, stic_script_vars.invalidDocumentNumber);
        return false;
    }
    else {
        sticClearFieldError(idEl);
        return true;
    }
}

/** 
* Comprueba si es un DNI correcto (entre 5 y 8 letras seguidas de la letra que corresponda).
* Acepta NIEs (Extranjeros con X, Y o Z al principio)
* http://trellat.es/funcion-para-validar-dni-o-nie-en-javascript/
*/
function isValidDNI(dni) {
    var numero, let, letra;
    var expresion_regular_dni = /^[XYZ]?\d{5,8}[A-Z]$/;

    dni = dni.toUpperCase();

    if (expresion_regular_dni.test(dni) === true){
        numero = dni.substr(0,dni.length-1);
        numero = numero.replace('X', 0);
        numero = numero.replace('Y', 1);
        numero = numero.replace('Z', 2);
        let = dni.substr(dni.length-1, 1);
        numero = numero % 23;
        letra = 'TRWAGMYFPDXBNJZSQVHLCKET';
        letra = letra.substring(numero, numero+1);
        if (letra != let) {
            return false;
        } else {
            return true;
        }
    } else {
        return false;
    }
}

/**
 * Valida si un cif es válido
 * Adaptada para javascript desde su original en:
 * http://www.michublog.com/informatica/8-funciones-para-la-validacion-de-formularios-con-expresiones-regulares 
 */
function isValidCif(cif) {
    cif.toUpperCase();
             
    cifRegEx1 = /^[ABEH][0-9]{8}/i;
    cifRegEx2 = /^[KPQS][0-9]{7}[A-J]/i;
    cifRegEx3 = /^[CDFGJLMNRUVW][0-9]{7}[0-9A-J]/i;
	
    if (cif.match(cifRegEx1) || cif.match(cifRegEx2) || cif.match(cifRegEx3)) {
    	control = cif.charAt(cif.length-1);
    	suma_A = 0;
    	suma_B = 0;
    	 
    	for (i = 1; i < 8; i++) {
    		if (i % 2 == 0) suma_A += parseInt(cif.charAt(i));
    		else {
    			t = (parseInt(cif.charAt(i)) * 2).toString();
    			p = 0;
    			 
    			for (j = 0; j < t.length; j++) {
    				p += parseInt(t.charAt(j));
    			}
    			suma_B += p;
    		}
    	}
    	 
    	suma_C = (parseInt(suma_A + suma_B)) + '';	// Así se convierte en cadena
    	suma_D = (10 - parseInt(suma_C.charAt(suma_C.length - 1))) % 10;
    	 
    	letras = 'JABCDEFGHI';
    	 
    	if (control >= '0' && control <= '9') return (control == suma_D);
    	else return (control.toUpperCase() == letras[suma_D]);
    }
    else return false;
}
