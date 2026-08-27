<?php

define('sugarEntry', true);

class SugarRestApiCall
{

    public static $objSCP;
    public $username;
    public $password;
    public $url;
    public $session_id;
    public $destinationModule;
    /** Idioma que se le pide al CRM al autenticar (declarada: en PHP 8.2+ crear
     *  la propiedad al vuelo emite un "deprecated" en CADA petición). */
    public $language;

    /**
     * El último error que ha contado el CRM, en texto.
     *
     * EXISTE PORQUE NO EXISTÍA: `set_entry()` hacía `$r->id` sin mirar si la
     * respuesta era un error, así que el motivo real («Access Denied», «Invalid
     * Session ID», un campo que no existe) se tiraba a la basura y arriba solo
     * quedaba un null. Con esto, quien escribe puede DECIR por qué no pudo.
     */
    public $lastError = '';

    /**
     * Contadores de la petición HTTP en curso (no de la instancia): cuántas
     * llamadas al CRM se han hecho y cuánto han costado. Los lee
     * `sticpa_crm_timing_header()` para la cabecera `Server-Timing`, que es la
     * única forma de saber si una pantalla va lenta por el CRM o por otra cosa.
     */
    public static $callCount = 0;
    public static $callMs = 0.0;
    public static $callLog = array();

    /**
     * Handle de cURL reutilizado en TODAS las llamadas de esta petición.
     * Antes se creaba y destruía uno por llamada, así que cada round-trip pagaba
     * DNS + TCP + handshake TLS completos. Una sola pantalla hace de 2 a 40
     * llamadas al CRM: era el coste fijo más caro del área privada.
     */
    private $curlHandle = null;

    /**
     * Ventana durante la cual se reutiliza el session_id del CRM guardado en la
     * sesión de PHP. La sesión de WordPress dura un año (ver sticpa_session_ttl)
     * pero la del CRM caduca mucho antes: con un id viejo la primera llamada
     * falla y cuesta 3 round-trips (fallo → login → reintento), y eso caía
     * SIEMPRE en el primer tap al volver a la app. Renovar antes cuesta 1.
     */
    const SESSION_MAX_AGE = 1200; // 20 minutos

    private function __construct($url, $username, $password, $destinationModule)
    {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->language = get_locale() == 'ca' ? 'ca_ES' : get_locale();
        $this->destinationModule = $destinationModule;
        if ($this->hasFreshSessionId()) {
            $this->session_id = $_SESSION['api_session_id'];
        }
        else if (!isset(self::$objSCP) || self::$objSCP->url !== $url || self::$objSCP->username !== $username || self::$objSCP->password !== $password) {
            $this->storeSessionId($this->login());
            self::$objSCP = $this;
        } else {
            $this->session_id = self::$objSCP->session_id;
        }

    }

    /**
     * ¿Hay un session_id del CRM en sesión y es lo bastante reciente para fiarse?
     * Sin marca de tiempo (sesiones creadas antes de este cambio) se considera
     * caducado: mejor un login de más que una llamada fallida más un reintento.
     */
    private function hasFreshSessionId()
    {
        if (empty($_SESSION['api_session_id'])) {
            return false;
        }
        $stamped = isset($_SESSION['api_session_time']) ? (int) $_SESSION['api_session_time'] : 0;
        if ($stamped <= 0) {
            return false;
        }
        $age = time() - $stamped;
        return $age >= 0 && $age < self::sessionMaxAge();
    }

    /** Guarda el session_id del CRM en sesión junto a su marca de tiempo. */
    private function storeSessionId($sessionId)
    {
        $this->session_id = $sessionId;
        $_SESSION['api_session_id'] = $sessionId;
        $_SESSION['api_session_time'] = time();
    }

    private static function sessionMaxAge()
    {
        return self::intSetting('sticpa_crm_session_max_age', self::SESSION_MAX_AGE);
    }

    /** Ajuste entero filtrable, tolerante a que WordPress no esté cargado (tests). */
    private static function intSetting($filter, $default)
    {
        $value = function_exists('apply_filters') ? apply_filters($filter, $default) : $default;
        return (int) $value;
    }

    /**
     * Devuelve el handle de cURL de esta instancia, limpio de opciones previas.
     * curl_reset() vacía las opciones pero CONSERVA el pool de conexiones, que
     * es justo lo que queremos reutilizar.
     */
    private function getCurlHandle()
    {
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        } else {
            curl_reset($this->curlHandle);
        }
        return $this->curlHandle;
    }

    /**
     * Tira el handle de cURL. Se llama tras un error de red por precaución: el
     * handle guarda su pool de conexiones y, tras un timeout, esa conexión queda
     * a medias (el servidor puede seguir escribiendo en ella más tarde).
     * Empezar de cero cuesta un handshake, pero solo en el camino de error.
     * Nota: en las pruebas contra un servidor que se cuelga a propósito, cURL ya
     * se recuperaba solo; esto es cinturón, no la corrección de un fallo visto.
     */
    private function closeCurlHandle()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    public function __destruct()
    {
        $this->closeCurlHandle();
    }

    public static function getObjSCP() {
        if (self::$objSCP == null) {
            $scp_sugar_rest_url = get_option('sticpa_scp_rest_url');
            $scp_sugar_username = get_option('sticpa_scp_username');
            $scp_sugar_password = get_option('sticpa_scp_password');
            self::$objSCP = new SugarRestApiCall($scp_sugar_rest_url, $scp_sugar_username, $scp_sugar_password, getDestinationModule());
        }
        return self::$objSCP;
    }

    public function call($method, $parameters, $url, $retry = false)
    {
        $curl_request = $this->getCurlHandle();

        curl_setopt($curl_request, CURLOPT_URL, $url);
        curl_setopt($curl_request, CURLOPT_POST, 1);
        // HTTP/1.1 (antes 1.0): 1.0 no negocia keep-alive, así que el servidor
        // cerraba la conexión tras cada respuesta y la siguiente llamada volvía
        // a pagar el handshake TLS.
        curl_setopt($curl_request, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        // Sin cabeceras dentro del cuerpo: cURL las separa por su cuenta. Antes
        // se partía la respuesta a mano con explode("\r\n\r\n"), que es lo que
        // obligaba a usar HTTP/1.0 (con 1.1 puede llegar chunked o 100-continue).
        curl_setopt($curl_request, CURLOPT_HEADER, 0);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl_request, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($curl_request, CURLOPT_TCP_KEEPALIVE, 1);
        // Respuestas comprimidas ('' = acepta lo que soporte cURL y descomprime).
        curl_setopt($curl_request, CURLOPT_ENCODING, '');
        // Antes NO había ningún timeout: un CRM colgado se comía el
        // max_execution_time entero y la WebView se quedaba en blanco.
        curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, self::intSetting('sticpa_crm_connect_timeout', 5));
        curl_setopt($curl_request, CURLOPT_TIMEOUT, self::intSetting('sticpa_crm_timeout', 20));

        $jsonEncodedData = json_encode($parameters);

        $post = array(
            "method" => $method,
            "input_type" => "JSON",
            "response_type" => "JSON",
            "rest_data" => $jsonEncodedData,
        );

        curl_setopt($curl_request, CURLOPT_POSTFIELDS, $post);
        $startedAt = microtime(true);
        $result = curl_exec($curl_request);

        if ($result === false) {
            // Timeout o error de red: se devuelve null y cada consumidor pinta su
            // estado vacío, en vez de dejar la petición colgada.
            $curlError = curl_error($curl_request);
            // Y se tira el handle: la conexión que ha fallado no vale para la
            // siguiente llamada (ver closeCurlHandle).
            $this->closeCurlHandle();
            $this->lastError = 'red: ' . $curlError;
            self::noteCall($method, $parameters, $startedAt, $this->lastError);
            error_log('[sticpa] Llamada al CRM fallida (' . $method . '): ' . $curlError);
            return null;
        }

        $response = json_decode($result);
        $error = self::errorFrom($response);
        self::noteCall($method, $parameters, $startedAt, $error);

        if (isset($response->number) && $response->number == 11 && !$retry) {
            // Sesión del CRM caducada: re-login y reintento. El 'session' que
            // traen los $parameters es el viejo, así que hay que refrescarlo o el
            // reintento fallaría igual (antes se reenviaba el id muerto).
            $this->storeSessionId($this->login());
            if (is_array($parameters) && array_key_exists('session', $parameters)) {
                $parameters['session'] = $this->session_id;
            }
            return $this->call($method, $parameters, $url, true);
        }

        // El error se GUARDA y se registra. Antes llegaba arriba como un null
        // pelado y el motivo del CRM no lo veía nadie.
        if ($error !== '') {
            $this->lastError = $error;
            error_log('[sticpa] El CRM ha rechazado ' . $method
                . (isset($parameters['module_name']) ? ' (' . $parameters['module_name'] . ')' : '')
                . ': ' . $error);
        } else {
            $this->lastError = '';
        }

        return $response;
    }

    /**
     * ¿Es esta respuesta un error del CRM? Devuelve el motivo, o '' si va bien.
     *
     * SuiteCRM contesta **200 con un cuerpo de error** `{number, name,
     * description}`: no hay código HTTP que mirar. Una respuesta buena no trae
     * `number`, así que su simple presencia (con nombre, o con número distinto
     * de cero) es la señal.
     */
    public static function errorFrom($response)
    {
        if (!is_object($response) || !isset($response->number)) {
            return '';
        }
        $number = (int) $response->number;
        $hasName = isset($response->name) && (string) $response->name !== '';
        if ($number === 0 && !$hasName) {
            return '';
        }
        $parts = array();
        if ($hasName) {
            $parts[] = (string) $response->name;
        }
        if (isset($response->description) && (string) $response->description !== '') {
            $parts[] = (string) $response->description;
        }
        $parts[] = '#' . $number;
        return implode(' — ', $parts);
    }

    /** Apunta una llamada en los contadores de la petición. */
    private static function noteCall($method, $parameters, $startedAt, $error = '')
    {
        $ms = (microtime(true) - (float) $startedAt) * 1000;
        self::$callCount++;
        self::$callMs += $ms;
        // El detalle solo se guarda si alguien lo va a leer: sin el filtro
        // puesto, ~40 entradas por petición serían memoria para nada.
        if (count(self::$callLog) < 60) {
            self::$callLog[] = array(
                'method' => (string) $method,
                'module' => isset($parameters['module_name']) ? (string) $parameters['module_name'] : '',
                'ms' => round($ms, 1),
                'error' => (string) $error,
            );
        }
    }

    // login into sugar
    public function login()
    {
        $login_parameters = array(
            "user_auth" => array(
                "user_name" => $this->username,
                "password" => md5($this->password),
            ),

            // Application_name and Notifyonsave params must be set to allow SugarCRM
            // sending email notifications when assigning records to users through Private Area

            //application name
            "application_name" => "Case portal",

            //name value list for 'language' and 'notifyonsave'
            "name_value_list" => array(
                array(
                    "name" => "notifyonsave",
                    "value" => true,
                ),
                array(
                    "name" => "language",
                    "value" => $this->language,
                ),
            ),
        );
        $login_response = $this->call('login', $login_parameters, $this->url);
        $session_id = $login_response->id ?? null;
        return $session_id;
    }

    // login into Portal (login call in contacts/accounts module, retrieves contact/account data)
    public function PortalLogin($username, $password)   
    {
        /* $username and $password are passed from login page */
        if ($this->destinationModule === 'Contacts') {
            $selectFields = array('id', 'stic_pa_username_c', 'stic_pa_password_c', 'salutation', 'first_name', 'last_name', 'email1', 'account_id', 'title', 'phone_work', 'phone_mobile', 'assigned_user_id', 'assigned_user_name', 'name', 'stic_relationship_type_c');
        } else {
            $selectFields = array('id', 'stic_pa_username_c', 'stic_pa_password_c', 'name', 'email1', 'phone_office', 'assigned_user_id');
        }
        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $this->destinationModule,
            'query' => "stic_pa_username_c = '{$username}' AND  stic_pa_password_c = '{$password}'",
            'order_by' => '',
            'offset' => 0,
            'select_fields' => $selectFields,
            'max_results' => 0,
        );
        $get_entry_list_result = $this->call("get_entry_list", $get_entry_list, $this->url);
        return $get_entry_list_result;
    }

    public function num_asc($a, $b)
    {
        return strcmp($a->name_value_list->case_number->value, $b->name_value_list->case_number->value);
    }
    public function num_desc($a, $b)
    {
        return strcmp($b->name_value_list->case_number->value, $a->name_value_list->case_number->value);
    }
    public function name_asc($a, $b)
    {
        return strnatcasecmp($a->name_value_list->name->value, $b->name_value_list->name->value);
    }
    public function name_desc($a, $b)
    {
        return strnatcasecmp($b->name_value_list->name->value, $a->name_value_list->name->value);
    }
    public function date_asc($a, $b)
    {
        return strcmp($a->name_value_list->date_entered->value, $b->name_value_list->date_entered->value);
    }
    public function date_desc($a, $b)
    {
        return strcmp($b->name_value_list->date_entered->value, $a->name_value_list->date_entered->value);
    }
    public function date_asc2($a, $b)
    {
        return strcmp($a->name_value_list->date_start->value, $b->name_value_list->date_start->value);
    }
    public function date_desc2($a, $b)
    {
        return strcmp($b->name_value_list->date_start->value, $a->name_value_list->date_start->value);
    }
    public function prior_asc($a, $b)
    {
        return strcmp($a->name_value_list->priority->value, $b->name_value_list->priority->value);
    }
    public function prior_desc($a, $b)
    {
        return strcmp($b->name_value_list->priority->value, $a->name_value_list->priority->value);
    }
    public function status_asc($a, $b)
    {
        return strnatcasecmp($a->name_value_list->status->value, $b->name_value_list->status->value);
    }
    public function status_desc($a, $b)
    {
        return strnatcasecmp($b->name_value_list->status->value, $a->name_value_list->status->value);
    }

    // get language definition from any module
    public function getLanguageDefinition($moduleName)
    {
        $get_language_parameters = array(
            'session' => $this->session_id,
            'modules' => $moduleName,
        );
        $get_language_result = $this->call("get_language_definition", $get_language_parameters, $this->url);
        return $get_language_result;
    }

    // get field definition from any module
    public function getFieldDefinition($moduleName, $fields = array())
    {
        $get_field_definition_parameters = array(
            'session' => $this->session_id,
            'module_name' => $moduleName,
            'fields' => $fields,
        );
        $get_field_definition_result = $this->call("get_module_fields", $get_field_definition_parameters, $this->url);
        return $get_field_definition_result;
    }

    // Add or Update given record
    public function set_entry($module_name, $set_entry_dataArray)
    {
        $nameValueListArray = array();
        $i = 0;
        foreach ($set_entry_dataArray as $field => $value) {
            $nameValueListArray[$i]['name'] = $field;
            $nameValueListArray[$i]['value'] = $value;
            $i++;
        }
        $set_entry_parameters = array(
            "session" => $this->session_id,
            "module_name" => $module_name,
            "name_value_list" => $nameValueListArray,
        );
        $set_entry_result = $this->call("set_entry", $set_entry_parameters, $this->url);

        // ANTES ERA `$set_entry_result->id` A PELO. Si el CRM contestaba un
        // error (sin acceso al módulo, sesión inválida, campo inexistente) esto
        // devolvía null con un aviso de PHP y el motivo se perdía: es la razón
        // por la que «pasar lista» podía no escribir nada y no decir nada.
        if (!is_object($set_entry_result) || empty($set_entry_result->id)) {
            if ($this->lastError === '') {
                $this->lastError = 'set_entry sin id: ' . substr((string) wp_json_encode($set_entry_result), 0, 300);
            }
            error_log('[sticpa] set_entry(' . $module_name . ') no ha devuelto id: ' . $this->lastError);
            return null;
        }

        return $set_entry_result->id;
    }

    public function set_relationship($moduleName, $recordId, $relationship, $relatedIds = array())
    {
        $setRelationshipParameters = array(
            "session" => $this->session_id,
            "module_name" => $moduleName,
            "module_id" => $recordId,
            "link_field_name" => $relationship,
            "related_ids" => $relatedIds,
        );
        $setRelationshipResult = $this->call("set_relationship", $setRelationshipParameters, $this->url);

        // `set_relationship` contesta {created, failed, deleted}. Nadie miraba
        // `failed`, así que una asistencia podía quedarse SIN sesión o SIN
        // inscripción —huérfana, y el CRM no la cuenta en el porcentaje— sin
        // que constara en ningún sitio.
        if (!is_object($setRelationshipResult)) {
            if ($this->lastError === '') {
                $this->lastError = 'set_relationship sin respuesta';
            }
            return false;
        }
        if (!empty($setRelationshipResult->failed)) {
            $this->lastError = 'set_relationship(' . $relationship . ') ha fallado en '
                . (int) $setRelationshipResult->failed . ' de ' . count((array) $relatedIds);
            error_log('[sticpa] ' . $this->lastError);
            return false;
        }
        if (isset($setRelationshipResult->created) && (int) $setRelationshipResult->created === 0
            && empty($setRelationshipResult->deleted)) {
            // Cero creadas y cero borradas: o ya estaba (inofensivo) o no ha
            // hecho nada. No es un fallo, pero se deja rastro por si el
            // diagnóstico lo necesita.
            error_log('[sticpa] set_relationship(' . $relationship . ') no ha creado nada (¿ya existía?)');
        }
        return $setRelationshipResult;
    }

    // Add or Update given record
    public function set_document_revision($note)
    {
        $set_entry_parameters = array(
            "session" => $this->session_id,
            "note" => $note,
        );
        $set_entry_result = $this->call("set_document_revision", $set_entry_parameters, $this->url);
        $recordID = $set_entry_result->id;
        return $recordID;
    }

    // Add or Update given record
    public function set_image($record_file_data)
    {
        $set_entry_parameters = array(
            "session" => $this->session_id,
            "image_data" => $record_file_data,
        );
        $set_entry_result = $this->call("set_image", $set_entry_parameters, $this->url);
        $result = $set_entry_result;
        return $result;
    }

    // Get given record
    public function get_image($image_data)
    {
        $set_entry_parameters = array(
            "session" => $this->session_id,
            "image_data" => $image_data,
        );
        $set_entry_result = $this->call("get_image", $set_entry_parameters, $this->url);
        return $set_entry_result;
    }

    // get user information
    public function getUserInformation($userId)
    {
        $get_entry_parameters = array(
            'session' => $this->session_id,
            'module_name' => $this->destinationModule,
            'id' => $userId,
            'select_fields' => '',
            'link_name_to_fields_array' => '',

        );
        $get_entry_result = $this->call("get_entry", $get_entry_parameters, $this->url);
        return $get_entry_result;
    }

    // Check if user exists
    public function getUserExists($username)
    {
        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $this->destinationModule,
            'query' => "stic_pa_username_c = '{$username}'",
            'order_by' => '',
            'offset' => 0,
            'select_fields' => array('id', 'stic_pa_username_c'),
            'max_results' => 0,
        );
        $get_entry_list_result = $this->call("get_entry_list", $get_entry_list, $this->url);
        if (isset($get_entry_list_result->entry_list)) {
            $isUser = $get_entry_list_result->entry_list[0]->name_value_list->stic_pa_username_c->value;
            if ($isUser == $username) {
                return true;
            } 
        }
        return false;
    }

    // Get user information by username
    public function getUserInformationByUsername($username)
    {
        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $this->destinationModule,
            'query' => "stic_pa_username_c = '{$username}'",
            'order_by' => '',
            'offset' => 0,
            'select_fields' => array('id', 'stic_pa_username_c', 'stic_pa_password_c', 'email1'),
            'max_results' => 0,
        );
        $get_entry_list_result = $this->call("get_entry_list", $get_entry_list, $this->url);
        $isUser = $get_entry_list_result->entry_list[0]->name_value_list->stic_pa_username_c->value;
        if ($isUser == $username) {
            return $get_entry_list_result;
        } else {
            return false;
        }
    }

    // Get all email addresses
    public function getAllEmail()
    {
        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $this->destinationModule,
            'query' => "",
            'order_by' => '',
            'offset' => 0,
            'select_fields' => array('id', 'email1'),
            'max_results' => 0,
        );
        $get_entry_list_result = $this->call("get_entry_list", $get_entry_list, $this->url);
        $getAllEmailsData = $get_entry_list_result->entry_list;

        foreach ($getAllEmailsData as $getAllEmailsObj) {
            $getEmails[] = $getAllEmailsObj->name_value_list->email1->value;
        }
        return $getEmails;
    }

    // Get logged user related records for a certain module
    public function getRelatedElementsForLoggedUser($params)
    {
        $get_relationship_params = array(
            'session' => $this->session_id,
            'module_name' => $params['module_name'],
            "module_id" => $params['module_id'],
            "link_field_name" => $params['link_field_name'],
            "related_module_query" => isset($params['related_module_query']) ? $params['related_module_query'] : '', //set here the filters for records to show
            "related_fields" => $params['related_fields'],
            "related_module_link_name_to_fields_array" => $params['related_module_link_name_to_fields_array'],
            "deleted" => $params['deleted'],
            "order_by" => $params['order_by'],
            "offset" => $params['offset'],
            "limit" => $params['limit'],
        );

        if ($params['offset'] < 0) {
            $params['offset'] = 0;
        }
        $get_entry_list_result = $this->call("get_relationships", $get_relationship_params, $this->url);
        $workarray = $get_entry_list_result->entry_list ?? null;

        return self::attachLinkList($workarray, $get_entry_list_result->relationship_list ?? null);
    }

    /**
     * Pega los enlaces anidados dentro de cada registro, como `->link_list`.
     *
     * POR QUÉ EXISTE ESTO. `get_relationships` de la API v4.1 NO devuelve los
     * enlaces dentro de cada registro: los devuelve en `relationship_list`, un
     * HERMANO de `entry_list` indexado en paralelo (mismo orden, misma
     * posición). Quien solo se queda con `entry_list` —que es lo que hacía este
     * método— tira a la basura todo lo que se pidió en
     * `related_module_link_name_to_fields_array`, y encima sin error: la llamada
     * responde 200 y los registros llegan, solo que pelados. El síntoma es una
     * lista vacía donde el CRM sí tiene datos.
     *
     * Se junta aquí, en el transporte, y no en cada pantalla, porque la forma
     * de la respuesta de la API es asunto de esta clase: quien la use encuentra
     * el enlace donde es natural buscarlo, en `$registro->link_list`.
     *
     * Es estática y pública para poder probarla sin sesión ni red.
     *
     * @param array|null $entryList        `entry_list` tal cual llega.
     * @param array|null $relationshipList `relationship_list` tal cual llega.
     * @return array|null El mismo `entry_list`, con `link_list` donde lo haya.
     */
    public static function attachLinkList($entryList, $relationshipList)
    {
        if (!is_array($entryList) || empty($relationshipList) || !is_array($relationshipList)) {
            return $entryList;
        }
        foreach ($entryList as $i => $record) {
            // Los registros son objetos, así que se modifican en su sitio. Se
            // exige que la posición exista: si las dos listas no vinieran
            // alineadas, colgarle a alguien los datos de otro sería peor que
            // no tener datos.
            if (!is_object($record) || !isset($relationshipList[$i]->link_list)) {
                continue;
            }
            $record->link_list = $relationshipList[$i]->link_list;
        }
        return $entryList;
    }

    // get record details from any module
    public function getRecordDetail($id, $moduleName, $fieldsToReturn = null)
    {
        $getEntry = array(
            'session' => $this->session_id,
            'module_name' => $moduleName,
            'id' => $id,
            'select_fields' => $fieldsToReturn,
            'link_name_to_fields_array' => null,
        );
        $getEntryResults = $this->call("get_entry", $getEntry, $this->url);
        return $getEntryResults;
    }

    
    // get document revision
    public function getDocumentRevision($id)
    {
        $getDocumentRevision = array(
            'session' => $this->session_id,
            'i' => $id,
        );
        $getDocumentRevisionResults = $this->call("get_document_revision", $getDocumentRevision, $this->url);
        return $getDocumentRevisionResults;
    }

    // Get all records from a module using a give query
    public function getRecordsModule($moduleName, $query = '', $fieldsToReturn = array('id', 'name'), $relationshipFields = null)
    {
        $getEntryList = array(
            'session' => $this->session_id,
            'module_name' => $moduleName,
            'query' => $query,
            'order_by' => '',
            'offset' => 0,
            'select_fields' => $fieldsToReturn,
            'link_name_to_fields_array' => $this->parseRelationshipFields($relationshipFields),
            'deleted' => 0,
            'max_results' => 0,
        );
        $getEntryListResult = $this->call("get_entry_list", $getEntryList, $this->url);

        return self::flattenRelationshipFields(
            $getEntryListResult->entry_list ?? null,
            $getEntryListResult->relationship_list ?? null,
            $relationshipFields
        );
    }

    /**
     * Mete cada enlace pedido en `name_value_list` como si fuera un campo más.
     *
     * DOS COSAS QUE ANTES ESTABAN MAL, y las dos daban datos silenciosamente
     * equivocados en vez de un error:
     *
     * 1. Se leía SIEMPRE `link_list[0]`, o sea el primer enlace, para todos los
     *    campos pedidos. Con dos relaciones («el grupo» y «la persona»), las dos
     *    recibían el valor de la misma. Ahora se busca el enlace POR NOMBRE.
     * 2. Se accedía a `relationship_list[$index]->link_list[0]->records[0]->…`
     *    sin comprobar nada. Un registro sin ese enlace —lo normal: es justo lo
     *    que se quiere detectar, «este participante no tiene grupo»— reventaba
     *    en avisos de PHP encadenados. Ahora esa ausencia es una cadena vacía,
     *    que es el dato correcto.
     *
     * @param array|null $entryList
     * @param array|null $relationshipList
     * @param array|null $relationshipFields  clave => array('relationshipName' => …)
     * @return array|null
     */
    public static function flattenRelationshipFields($entryList, $relationshipList, $relationshipFields)
    {
        if (!is_array($entryList) || !is_array($relationshipFields) || empty($relationshipFields)) {
            return $entryList;
        }

        foreach ($entryList as $index => $record) {
            if (!is_object($record) || !isset($record->name_value_list)) {
                continue;
            }
            $links = isset($relationshipList[$index]->link_list) && is_array($relationshipList[$index]->link_list)
                ? $relationshipList[$index]->link_list
                : array();

            $linkIndex = -1;
            foreach ($relationshipFields as $keyField => $spec) {
                $linkIndex++;
                // Se tolera tanto la forma documentada (array con
                // 'relationshipName') como que se pase el nombre del enlace a
                // pelo: antes, un string aquí era un TypeError fatal en PHP 8 y
                // se llevaba la página entera por delante.
                if (is_array($spec)) {
                    $linkName = isset($spec['relationshipName']) ? (string) $spec['relationshipName'] : '';
                } else {
                    $linkName = (string) $spec;
                }

                $found = self::firstLinked($links, $linkName, $linkIndex);
                $record->name_value_list->$keyField = (object) array(
                    'name' => $keyField,
                    'value' => $found['name'],
                );
                // Y el ID del registro enlazado, en `<campo>_id`. Hace falta
                // para INDEXAR por el enlace (agrupar relaciones por grupo, por
                // ejemplo): con el nombre solo, dos grupos que se llamen igual
                // son indistinguibles, y el nombre no sirve para navegar.
                $idKey = $keyField . '_id';
                $record->name_value_list->$idKey = (object) array(
                    'name' => $idKey,
                    'value' => $found['id'],
                );
                // Y el registro enlazado ENTERO en `<campo>_link`, con la misma
                // forma que el resto (`->campo->value`). Sin esto, quien quiera
                // un campo que no sea el nombre —los apellidos para ordenar, la
                // edad, el móvil— tendría que pedir el contacto otra vez.
                $linkKey = $keyField . '_link';
                $record->name_value_list->$linkKey = $found['record'];
            }
        }

        return $entryList;
    }

    /**
     * El primer registro del enlace que se llame $linkName: su id y su nombre.
     *
     * Devuelve los dos vacíos si el enlace no está o no trae registros — que es
     * un resultado legítimo, no un fallo: es justo lo que dice «este
     * participante no tiene grupo». Si el registro no trae `name`, el nombre se
     * compone con nombre y apellidos, que es lo que pasa con Contacts.
     *
     * @param int $linkIndex Posición del enlace en lo que se pidió, para cuando
     *                        la API no dice de qué enlace es cada bloque.
     * @return array{id: string, name: string, record: object|null}
     */
    private static function firstLinked($links, $linkName, $linkIndex = 0)
    {
        $empty = array('id' => '', 'name' => '', 'record' => null);

        // 1) Por NOMBRE, si la API lo dice. Es lo fiable.
        if ($linkName !== '') {
            foreach ($links as $link) {
                if (!is_object($link) || !isset($link->name) || (string) $link->name !== $linkName) {
                    continue;
                }
                $found = self::firstRecordOf($link);
                if ($found !== null) {
                    return $found;
                }
                return $empty;   // el enlace existe y esta vacio: eso es un dato
            }
        }

        // 2) Si NINGÚN bloque trae nombre, se va POR POSICIÓN: `link_list` llega
        //    en el mismo orden en que se pidieron los enlaces.
        //
        //    AQUÍ ESTABA EL FALLO QUE LO EXPLICA TODO. Antes se recorría la
        //    lista y se cogía el PRIMER bloque con registros — para todos los
        //    campos. Pidiendo dos enlaces («el grupo» y «la persona»), los dos
        //    recibían el primero: `grupo` se quedaba con el id de la persona,
        //    no coincidía con ningún grupo, y la consulta de UNA llamada se
        //    daba por fallida. De ahí que todo cayera a los respaldos de 1+N
        //    llamadas, y de ahí que la pantalla vaya lenta.
        $anyNamed = false;
        foreach ($links as $link) {
            if (is_object($link) && isset($link->name) && (string) $link->name !== '') {
                $anyNamed = true;
                break;
            }
        }
        if ($anyNamed) {
            return $empty;      // hay nombres y el nuestro no está: no es nuestro
        }

        $list = array_values(is_array($links) ? $links : array());
        if (!isset($list[$linkIndex]) || !is_object($list[$linkIndex])) {
            return $empty;
        }
        $found = self::firstRecordOf($list[$linkIndex]);
        return ($found !== null) ? $found : $empty;
    }

    /** El primer registro con datos de un bloque de enlace, o null. */
    private static function firstRecordOf($link)
    {
        if (!is_object($link) || empty($link->records) || !is_array($link->records)) {
            return null;
        }
        foreach ($link->records as $rec) {
            $lv = isset($rec->link_value) ? $rec->link_value : null;
            if (!$lv) {
                continue;
            }
            $id = isset($lv->id->value) ? trim((string) $lv->id->value) : '';
            $name = isset($lv->name->value) ? trim((string) $lv->name->value) : '';
            if ($name === '') {
                $first = isset($lv->first_name->value) ? trim((string) $lv->first_name->value) : '';
                $last = isset($lv->last_name->value) ? trim((string) $lv->last_name->value) : '';
                $name = trim($first . ' ' . $last);
            }
            if ($id !== '' || $name !== '') {
                return array('id' => $id, 'name' => $name, 'record' => $lv);
            }
        }
        return null;
    }

    // Portal login using the permanent access token (custom field ajmcm_pa_token_c).
    // $token must be pre-sanitized to [a-f0-9] by the caller.
    public function PortalLoginByToken($token, $module)
    {
        if ($module === 'Accounts') {
            $selectFields = array('id', 'name', 'stic_pa_username_c', 'assigned_user_id', 'email1', 'ajmcm_pa_token_c');
        } else {
            $selectFields = array('id', 'name', 'stic_pa_username_c', 'account_id', 'assigned_user_id', 'email1', 'ajmcm_pa_token_c');
        }
        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $module,
            'query' => "ajmcm_pa_token_c = '{$token}'",
            'order_by' => '',
            'offset' => 0,
            'select_fields' => $selectFields,
            'max_results' => 1,
            'deleted' => 0,
        );
        return $this->call("get_entry_list", $get_entry_list, $this->url);
    }

    // Find a contact/account by its private-area username (for admin tools).
    public function getContactByUsername($username, $module)
    {
        $username = str_replace(array("'", "\\"), '', $username);
        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $module,
            'query' => "stic_pa_username_c = '{$username}'",
            'order_by' => '',
            'offset' => 0,
            'select_fields' => array('id', 'name', 'stic_pa_username_c', 'ajmcm_pa_token_c', 'email1'),
            'max_results' => 1,
            'deleted' => 0,
        );
        $result = $this->call("get_entry_list", $get_entry_list, $this->url);
        return (isset($result->entry_list[0]) && $result->entry_list[0] != null) ? $result->entry_list[0] : null;
    }

    // Search contacts/accounts by free text: full name (first + last together),
    // first name, last name or private-area username. Returns a list of matches
    // (for the admin search box). $term is sanitized here.
    public function searchContacts($term, $module, $maxResults = 25)
    {
        $term = str_replace(array("'", "\\"), '', trim($term));
        if ($term === '') {
            return array();
        }
        $like = '%' . $term . '%';

        if ($module === 'Accounts') {
            // Accounts only have a single "name" field.
            $query = "(accounts.name LIKE '{$like}' OR accounts_cstm.stic_pa_username_c LIKE '{$like}')";
        } else {
            // Contacts: match the full name (first + last together) as well as
            // each part on its own, plus the private-area username.
            $query = "("
                . "CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE '{$like}' "
                . "OR contacts.last_name LIKE '{$like}' "
                . "OR contacts.first_name LIKE '{$like}' "
                . "OR contacts_cstm.stic_pa_username_c LIKE '{$like}')";
        }

        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $module,
            'query' => $query,
            'order_by' => '',
            'offset' => 0,
            'select_fields' => array('id', 'name', 'stic_pa_username_c', 'ajmcm_pa_token_c', 'email1'),
            'max_results' => (int) $maxResults,
            'deleted' => 0,
        );
        $result = $this->call("get_entry_list", $get_entry_list, $this->url);
        return $result->entry_list ?? array();
    }

    // Find a contact/account by email address (used by the magic-link request flow).
    public function getContactByEmail($email, $module)
    {
        $table = strtolower($module); // contacts / accounts
        $email = str_replace(array("'", "\\"), '', $email);
        $query = "{$table}.id IN (SELECT eabr.bean_id FROM email_addr_bean_rel eabr "
            . "INNER JOIN email_addresses ea ON ea.id = eabr.email_address_id "
            . "WHERE eabr.bean_module = '{$module}' AND eabr.deleted = 0 "
            . "AND ea.deleted = 0 AND ea.email_address = '{$email}')";
        $get_entry_list = array(
            'session' => $this->session_id,
            'module_name' => $module,
            'query' => $query,
            'order_by' => '',
            'offset' => 0,
            'select_fields' => array('id', 'name', 'email1'),
            'max_results' => 1,
            'deleted' => 0,
        );
        $result = $this->call("get_entry_list", $get_entry_list, $this->url);
        return (isset($result->entry_list[0]) && $result->entry_list[0] != null) ? $result->entry_list[0] : null;
    }

    protected function parseRelationshipFields($relationshipFields = array()) {
        $link_name_to_fields_array = array();
        if (is_array($relationshipFields)) {
            foreach ($relationshipFields as $relationshipField) {
                $link_name_to_fields_array[] = array(
                    'name' => $relationshipField['relationshipName'],
                    'value' => $relationshipField['fields']
                );
            }
        }
        return $link_name_to_fields_array;
    }

}
