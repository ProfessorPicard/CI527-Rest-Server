<?php
    //Setting the response headers to tell the receiver to not cache any data and to make a fresh request every time
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    /**
     * MySQL helper class, establishes database connection,
     * executes query strings and provides error handling
     */
    class MySqlHelper {
        private $mysqli;

        //Connects to our MySQL database when constructed
        public function __construct() {
            try {
                //tries to establish a connection to the MySQL database
                $this->mysqli = new mysqli("pb685.brighton.domains:3306",
                    "pb685_admin",
                    "Klipschagon69!",
                    "pb685_rest_server");
            } catch(Exception $e) {
                //Calls the error handler if something has gone wrong
                $this->errorHandler();
            }
        }

        /**
         * @param $query string SQL statement to be executed
         * @return bool|mysqli_result|null
         */
        public function executeQuery($query) {
            try {
                //tries to execute the provided SQL statement and return the results
                return mysqli_query($this->mysqli, $query);
            } catch (Exception $e) {
                //Calls the error handler if something has gone wrong
                $this->errorHandler();
            }
            return null;
        }

        private function errorHandler() {
            //Sets the response code to 500 and exits out of the script
            ResponseHelper::setResponseHeaders(500);
            exit();
        }
    }

    /**
     * ResponseHelper class, contains a static function for setting
     * the header response easily using the numeric code
     */
    class ResponseHelper{

        /**
         * @param $code numeric value of the response status code to be set
         * @return void
         */
        public static function setResponseHeaders($code) {
            switch ($code) {
                case 200:
                    header("HTTP/1.1 200 OK");
                    break;
                case 204:
                    header("HTTP/1.1 204 No Content");
                    break;
                case 400:
                    header("HTTP/1.1 400 Bad Request");
                    break;
                case 405:
                    header("HTTP/1.1 405 Method Not Allowed");
                    break;
                case 500:
                    header("HTTP/1.1 500 Internal Server Error");
                    break;
            }

        }
    }

    //Switch statement checking whether a GET, POST or a different request was made
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            $source = $_POST["source"];
            $target = $_POST["target"];
            $message = $_POST["message"];

            echo $source . $target . $message;
            break;
        case 'GET':

            //Get our 2 optional parameters
            $sourceIsEmpty = empty($_GET["source"]);
            $targetIsEmpty = empty($_GET["target"]);

            //If they are both empty return status 400 and break out of our switch statement
            if ($sourceIsEmpty && $targetIsEmpty) {
                ResponseHelper::setResponseHeaders(400);
                break;
            }

            //Set our values using a ternary operator, nice and clean :)
            $source = urldecode(empty($_GET["source"]) ? "" : $_GET["source"]);
            $target = urldecode(empty($_GET["target"]) ? "" : $_GET["target"]);

            //If our source parameter is not empty, validate what was in it
            if (!$sourceIsEmpty) {
                //If validation fails, return status 400 and break out of our switch statement
                if (!validateString($source)) {
                    ResponseHelper::setResponseHeaders(400);
                    break;
                }
            }

            //If our source parameter is not empty, validate what was in it
            if (!$targetIsEmpty) {
                //If validation fails, return status 400 and break out of our switch statement
                if (!validateString($target)) {
                    ResponseHelper::setResponseHeaders(400);
                    break;
                }
            }

            //If either of our parameters is not empty, start our database queries
            if (!$sourceIsEmpty || !$targetIsEmpty) {

                /**
                 * Base query to be used regardless of what parameter is empty.
                 * Using a double inner join to get all the data from our message table
                 * as well as joining the user table to both source and target
                 */
                $query = "SELECT messages.id, messages.timestamp as 'sent', source_user.username as 'source', target_user.username as 'target', messages.message 
                    FROM messages 
                    INNER JOIN users source_user on (source_user.id=messages.source) 
                    INNER JOIN users target_user on (target_user.id=messages.target) 
                    WHERE ";

                //If both parameters were present, set our WHERE to search on both target and source usernames
                if (!$targetIsEmpty && !$sourceIsEmpty) {
                    $query .= "source_user.username='$source' AND target_user.username='$target'";
                }

                //If only the target parameter is present, set our WHERE to search on only the target username
                if (!$targetIsEmpty && $sourceIsEmpty) {
                    $query .= "target_user.username='$target'";
                }

                //If only the source parameter is present, set our WHERE to search on only the source username
                if ($targetIsEmpty && !$sourceIsEmpty) {
                    $query .= "source_user.username='$source'";
                }

                //Initialise our MySqlHelper class
                $mysql = new MySqlHelper();

                //Get a result from the database using our constructed query
                $result = $mysql->executeQuery($query);

                //Gets all the rows from our result and returns them as an associated array ready to be parsed into JSON
                $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

                //Set header ready for JSON response
                header('Content-Type: application/json; charset=utf-8');

                //If there are no results in our response, return status 204 and break out of our switch statement
                if ($result->num_rows <= 0) {
                    ResponseHelper::setResponseHeaders(204);
                    break;
                }

                //return status 204 and send our JSON to the response body
                ResponseHelper::setResponseHeaders(200);
                echo json_encode(["messages" => $rows]);

            } else {
                //If neither of these parameters are present, return status 400 and break out of our switch statement
                ResponseHelper::setResponseHeaders(400);
                return;
            }
            break;
        default:
            //If a different CRUD method is used, return status 405 and break out of our switch statement
            ResponseHelper::setResponseHeaders(405);
            break;
    }

    /**
     * @param $str string to be validated
     * @return bool returns bool whether the string was valid
     */
    function validateString($str) {
        // Checking if length of string is between 1 and 3 or greater than 16
        if (strlen($str) > 0 && strlen($str) < 4 || strlen($str) > 16) {
            return false;
        }
        // Checking if characters are all alphanumeric, return false if not
        if(!ctype_alnum($str)) {
            return false;
        }
        //return true if both conditions are met
        return true;
    }