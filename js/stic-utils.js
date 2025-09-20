/*
* Place in this file any custom js you want to use in your plugin
*/
$ = jQuery;

$(document).ready(function(){
    // Run selectize
    $('select[multiple]').selectize();
    //hide the success messagges
    $('body').on('click keyup paste', function () {
        if (document.getElementById('successMsg')) { document.getElementById('successMsg').style.opacity = '0'; }

    })
});

function alerta(obj) {
    var lastName = '';
    obj.style.color = "red";
    result = window.confirm(stic_script_vars.changeConfirmation);
    if (result) {
        lastName = obj.value;
    }
    else {
        obj.value = lastName;
    }
}

function formatDateTimeLocal() {
    $("input[type=datetime-local]").each(function(){
        if (this.value){
            datetime = this.value;
            datetime = datetime.replace("T", " ");
            $(this).clone().appendTo('#stic-wp-pa').removeAttr('required').attr('type', 'text').val(datetime+':00').hide();
            $(this).attr('name', this+'_clone').attr('id', this+'_clone');
        }
    });
    
}

function confirmDelete(obj) {
    if (window.confirm(stic_script_vars.deleteConfirmation)) {
        $('#stic-action').val('delete');
        return true;
    } else {
        return false;
    }
}

function verifyIban(obj) {
    let paymentMethod = $('#payment_method').val();
    if (paymentMethod == 'direct_debit') {
        // Delete non alphanumeric characters and transform to uppercase
        obj.value = obj.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();

        result = IBAN.isValid(obj.value);
        if (!result) {
            alert(stic_script_vars.wrongIban);
            obj.setAttribute("invalid", '');
        }
        else {
            obj.removeAttribute("invalid");
        }
    }
}

function verifyFormIsValid() {
    let elements = document.querySelectorAll("[invalid]");
    if (typeof elements != "undefined" && elements != null && elements.length != null
    && elements.length > 0) {
        alert(stic_script_vars.invalidElements);
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
        alert(stic_script_vars.invalidDocumentNumber);
        idEl.setAttribute("invalid", '');
        return false;
    }
    else {
        idEl.removeAttribute("invalid");
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
