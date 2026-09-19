<?php

/**
 * ============================================================================
 *  ARCHIVO: handlers de los módulos retirados del área privada (19/09/2026)
 * ----------------------------------------------------------------------------
 *  ESTE ARCHIVO NO SE INCLUYE EN NINGUNA PARTE, y esa es toda su gracia: el
 *  código se guarda por si algún día se usan estos módulos, pero no se ejecuta.
 *
 *  Son los handlers de «Relaciones con la organización» y «Contactos de la
 *  organización», que venían del plugin original de SinergiaCRM. Sus pantallas
 *  están en `pages/archivo/`; el porqué, en `pages/archivo/README.md`.
 *
 *  ⚠️ SI ALGÚN DÍA SE RECUPERAN, NO SE PEGAN TAL CUAL.
 *
 *  Los dos se registran también como `admin_post_nopriv_*` —o sea, accesibles
 *  SIN haber iniciado sesión—, cogen TODO el `$_REQUEST` y lo vuelcan en
 *  `set_entry()`, y aceptan `stic-action=delete`. Tal y como están, cualquiera
 *  con la URL puede crear, sobrescribir o borrar registros del CRM: el de
 *  relaciones toca `stic_Contacts_Relationships` (de donde salen los grupos de
 *  cada persona en Pasar Lista) y el de contactos toca `Contacts` (las
 *  personas). Archivarlos no es solo limpieza: cierra eso.
 *
 *  Antes de volver a enchufarlos hacen falta, como mínimo:
 *    1. comprobar la sesión (`$_SESSION['scp_user_id']`), que es la ÚNICA
 *       autenticación posible aquí — para WordPress toda visita es anónima;
 *    2. un nonce;
 *    3. una lista blanca de campos, en vez de volcar `$_REQUEST` entero;
 *    4. comprobar que el registro que se toca es de quien lo toca.
 *
 *  Es lo que pide `plans/001-auth-gate-state-changing-handlers.md`, que sigue
 *  abierto para los handlers que SÍ están vivos.
 * ============================================================================
 */

// Se conserva TAL CUAL estaba el 19/09/2026, sin retoques, para que el día que
// alguien lo recupere vea exactamente de dónde parte.

/**
 * Action that manages creating and modificating Contacts/Accounts Relationships records
 */
add_action('admin_post_single_stic_relationships', 'prefix_admin_single_stic_relationships'); 
add_action('admin_post_nopriv_single_stic_relationships', 'prefix_admin_single_stic_relationships'); 
function prefix_admin_single_stic_relationships()
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_relationships";
        wp_redirect($redirectUrl);
        exit;
    } else {
        switch (getDestinationModule()) {
            case 'Accounts':
                $moduleName = 'stic_Accounts_Relationships'; 
                break;
            case 'Contacts':
                $moduleName = 'stic_Contacts_Relationships'; 
                break;
        }

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = stripslashes_deep($value);
        }

        $action = $moduleData['stic-action'];
        unset($moduleData['stic-action']); 
        if ($action === 'delete') {
            $moduleData['deleted'] = 1;
        }
        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate != null) {

            if ($action === 'delete') {
                $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_relationships&msgDelete=true";
            } else {
                $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
            }
            wp_redirect($redirect_url);
            exit;
        }
    }
}

/**
 * Action that manages creating and modificating Contacts records.
 */
add_action('admin_post_single_stic_contacts', 'prefix_admin_single_stic_contacts');
add_action('admin_post_nopriv_single_stic_contacts', 'prefix_admin_single_stic_contacts'); 
function prefix_admin_single_stic_contacts() 
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_contacts";
        wp_redirect($redirectUrl);
        exit;
    } else {
        $moduleName = 'Contacts'; 

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
        }
        $action = $moduleData['stic-action'];

        unset($moduleData['stic-action']); // to avoid passing the value to the API

        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate) {
            switch (getDestinationModule()) {
                case 'Accounts':
                    $relationship = 'accounts';
                    break;
                case 'Contacts':
                    $relationship = 'contacts';
                    break;
            }
            $relatedId = $_REQUEST[$relationship];
            // Relating the Document to the Contact record
            $resultRelationship = $objSCP->set_relationship('Contacts', $isUpdate, $relationship, array($relatedId));

            $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
        } else {
            $redirect_url = $_REQUEST['scp_current_url'] . '&msg=error' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
        }
        wp_redirect($redirect_url);
        exit;
    }
}
