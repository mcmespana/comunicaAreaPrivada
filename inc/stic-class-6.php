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

    private function __construct($url, $username, $password, $destinationModule)
    {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->language = get_locale() == 'ca' ? 'ca_ES' : get_locale();
        $this->destinationModule = $destinationModule;
        if (isset($_SESSION['api_session_id']) && $_SESSION['api_session_id']) {
            $this->session_id = $_SESSION['api_session_id'];
        }
        else if (!isset(self::$objSCP) || self::$objSCP->url !== $url || self::$objSCP->username !== $username || self::$objSCP->password !== $password) {
            $this->session_id = $this->login();
            $_SESSION['api_session_id'] =  $this->session_id;
            self::$objSCP = $this;
        } else {
            $this->session_id = self::$objSCP->session_id;
        }

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
        ob_start();
        $curl_request = curl_init();

        curl_setopt($curl_request, CURLOPT_URL, $url);
        curl_setopt($curl_request, CURLOPT_POST, 1);
        curl_setopt($curl_request, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        curl_setopt($curl_request, CURLOPT_HEADER, 1);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl_request, CURLOPT_FOLLOWLOCATION, 0);

        $jsonEncodedData = json_encode($parameters);

        $post = array(
            "method" => $method,
            "input_type" => "JSON",
            "response_type" => "JSON",
            "rest_data" => $jsonEncodedData,
        );

        curl_setopt($curl_request, CURLOPT_POSTFIELDS, $post);
        $result = curl_exec($curl_request);
        curl_close($curl_request);

        $result = explode("\r\n\r\n", $result, 2);
        $response = json_decode($result[1]);
        ob_end_flush();
        //echo "result: ";
        //print_r ($result);
        if (isset($response->number) && $response->number == 11 && !$retry) {
            $this->session_id = $this->login();
            $_SESSION['api_session_id'] = $this->session_id;
            return $this->call($method, $parameters, $url, true);
        }
        return $response;
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

        $recordID = $set_entry_result->id;
        return $recordID;
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

        return $workarray;
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
        // If there is any relationship field, we include it in the main result list
        if (is_array($relationshipFields)) {
            foreach ($relationshipFields as $keyField => $relationshipField) {
                if (isset($getEntryListResult->entry_list)) {
                    foreach ($getEntryListResult->entry_list as $index => $record) {
                        $getEntryListResult->entry_list[$index]->name_value_list->$keyField->name = $keyField;
                        $getEntryListResult->entry_list[$index]->name_value_list->$keyField->value = $getEntryListResult->relationship_list[$index]->link_list[0]->records[0]->link_value->name->value;
                    }
                }
            } 
        }

        return $getEntryListResult->entry_list ?? null;

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
