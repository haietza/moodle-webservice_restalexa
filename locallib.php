<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package   webservice_restalexa
 * @author    Michelle Melton <meltonml@appstate.edu>
 * @copyright 2018, Michelle Melton
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Forked and modified from webservice_restjson
 */

defined('MOODLE_INTERNAL') || die();
require_once("$CFG->dirroot/webservice/lib.php");

/**
 * REST service server implementation.
 *
 * @package    webservice_rest
 * @copyright  2009 Petr Skoda (skodak)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservice_restalexa_server extends webservice_base_server {

    /** @var string return method ('xml' or 'json') */
    protected $restformat;

    /**
     * Contructor
     *
     * @param string $authmethod authentication method of the web service (WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN, ...)
     * @param string $restformat Format of the return values: 'xml' or 'json'
     */
    public function __construct($authmethod) {
        parent::__construct($authmethod);
        $this->wsname = 'restalexa';
    }

    /**
     * This method parses the php input for the JSON request, web service token, and web service function.
     */
    protected function parse_request() {
        // Retrieve and clean the POST/GET parameters from the parameters specific to the server.
        parent::set_web_service_call_settings();

        // Get JSON request as string and object for processing.
        $datastring = file_get_contents('php://input');
        $data = json_decode(file_get_contents('php://input'), true );

        // Add GET parameters.
        $methodvariables = array_merge($_GET, (array) $data);

        // Set REST format to JSON.
        $this->restformat = 'json';

        // Set token to query string argument.
        $this->token = isset($methodvariables['wstoken']) ? $methodvariables['wstoken'] : null;
        unset($methodvariables['wstoken']);

        // Set web service function to query string argument.
        $this->functionname = isset($methodvariables['wsfunction']) ? $methodvariables['wsfunction'] : null;
        unset($methodvariables['wsfunction']);

        // Prepare request to send to web service.
        $request = array('request' => $datastring, 'token' => '');

        // Check if user accessToken is in request.
        if ($data['context']['System']['user']['accessToken']) {
            try {
                // Save web service user token passed in query string.
                $webserviceusertoken = $this->token;

                // Get user token from request.
                $this->token = $data['context']['System']['user']['accessToken'];

                // Check if user token is valid.
                $this->authenticate_user();
                $request['token'] = 'valid';
            } catch (Exception $ex) {
                // Provided user accessToken is invalid.
                // Pass web service user token to plugin for account linking request.
                $this->token = $webserviceusertoken;
            }
        }

        $this->parameters = $request;
    }

    /**
     * Send the result of function call to the WS client
     * formatted as XML document.
     */
    protected function send_response() {
        // Check that the returned values are valid.
        try {
            if ($this->function->returns_desc != null) {
                $validatedvalues = external_api::clean_returnvalue($this->function->returns_desc, $this->returns);
            } else {
                $validatedvalues = null;
            }
        } catch (Exception $ex) {
            $exception = $ex;
        }

        if (!empty($exception)) {
            $response = $this->generate_error($exception);
        } else {
            // We can now convert the response to the requested REST format.
            $response = json_encode($validatedvalues);
        }

        $this->send_headers();
        echo $response;
    }

    /**
     * Send the error information to the WS client
     * formatted as XML document.
     * Note: the exception is never passed as null,
     *       it only matches the abstract function declaration.
     * @param exception $ex the exception that we are sending
     */
    protected function send_error($ex=null) {
        $this->send_headers();
        echo $this->generate_error($ex);
    }

    /**
     * Build the error information matching the REST returned value format (JSON or XML)
     * @param exception $ex the exception we are converting in the server rest format
     * @return string the error in the requested REST format
     */
    protected function generate_error($ex) {
        $errorobject = new stdClass;
        $errorobject->exception = get_class($ex);
        $errorobject->errorcode = $ex->errorcode;
        $errorobject->message = $ex->getMessage();
        if (debugging() and isset($ex->debuginfo)) {
            $errorobject->debuginfo = $ex->debuginfo;
        }
        $error = json_encode($errorobject);
        return $error;
    }

    /**
     * Internal implementation - sending of page headers.
     */
    protected function send_headers() {
        header('Content-type: application/json');
        header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
        header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
        header('Pragma: no-cache');
        header('Accept-Ranges: none');
        // Allow cross-origin requests only for Web Services.
        // This allow to receive requests done by Web Workers or webapps in different domains.
        header('Access-Control-Allow-Origin: *');
    }

    /**
     * Internal implementation - recursive function producing XML markup.
     *
     * @param mixed $returns the returned values
     * @param external_description $desc
     * @return string
     */
    protected static function xmlize_result($returns, $desc) {
        if ($desc === null) {
            return '';
        } else if ($desc instanceof external_value) {
            if (is_bool($returns)) {
                // We want 1/0 instead of true/false here.
                $returns = (int)$returns;
            }
            if (is_null($returns)) {
                return '<VALUE null="null"/>'."\n";
            } else {
                return '<VALUE>'.htmlspecialchars($returns, ENT_COMPAT, 'UTF-8').'</VALUE>'."\n";
            }
        } else if ($desc instanceof external_multiple_structure) {
            $mult = '<MULTIPLE>'."\n";
            if (!empty($returns)) {
                foreach ($returns as $val) {
                    $mult .= self::xmlize_result($val, $desc->content);
                }
            }
            $mult .= '</MULTIPLE>'."\n";
            return $mult;
        } else if ($desc instanceof external_single_structure) {
            $single = '<SINGLE>'."\n";
            foreach ($desc->keys as $key => $subdesc) {
                $single .= '<KEY name="'.$key.'">'.self::xmlize_result($returns[$key], $subdesc).'</KEY>'."\n";
            }
            $single .= '</SINGLE>'."\n";
            return $single;
        }
    }
}