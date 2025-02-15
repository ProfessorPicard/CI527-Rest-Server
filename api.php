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
        private $id;

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
        /**
         * @param $query string SQL statement to be executed
         * @param $multi bool whether query is a multi query or a single query
         * @return bool|mysqli_result|null
         */
        public function executeQuery($query, $multi) {
            try {
                //tries to execute either a multi or single query depending on the value of $multi
                $response = ($multi) ? $this->mysqli->multi_query($query) : $this->mysqli->query($query);

                //This is to consume the result sent by the MySQL server to avoid
                // "Commands out of sync; you can't run this command now" error
                while($this->mysqli->next_result());

                //Gets the id of any modified or inserted rows
                $this->id = $this->mysqli->insert_id;

                //returns the results
                return $response;
            } catch (Exception $e) {
                //Calls the error handler if something has gone wrong
                $this->errorHandler();
            }
            return null;
        }

        /**
         * @return mixed gets the id for last modified/inserted row
         */
        public function getInsertId() {
            //Returns the id of the last modified row
            return $this->id;
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
                case 201:
                    header("HTTP/1.1 201 OK, record created");
                    break;
                case 204:
                    header("HTTP/1.1 204 Ok, no content");
                    break;
                case 400:
                    header("HTTP/1.1 400 Bad request");
                    break;
                case 405:
                    header("HTTP/1.1 405 Method not allowed");
                    break;
                case 500:
                    header("HTTP/1.1 500 Internal server error");
                    break;
            }
        }
    }

    //Switch statement checking whether a GET, POST or a different request was made
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':

            //Get our 3 required parameters
            $sourceIsEmpty = empty($_POST["source"]);
            $targetIsEmpty = empty($_POST["target"]);
            $messageIsEmpty = empty($_POST["message"]);

            //If any of our parameters are empty, return status 400 and break out of the switch statement
            if($sourceIsEmpty || $targetIsEmpty || $messageIsEmpty) {
                ResponseHelper::setResponseHeaders(400);
                break;
            }

            //Set our values using a ternary operator, nice and clean :)
            $source = empty($_POST["source"]) ? "" : $_POST["source"];
            $target = empty($_POST["target"]) ? "" : $_POST["target"];
            //Make sure to url decode the received message
            $message = urldecode(empty($_POST["message"]) ? "" : $_POST["message"]);

            //If our source parameter is not empty, validate what was in it
            if (!validateString($source)) {
                //If validation fails, return status 400 and break out of our switch statement
                ResponseHelper::setResponseHeaders(400);
                break;
            }

            //If our target parameter is not empty, validate what was in it
            if (!validateString($target)) {
                //If validation fails, return status 400 and break out of our switch statement
                ResponseHelper::setResponseHeaders(400);
                break;
            }

            //Initialise our MySqlHelper class
            $mysql = new MySqlHelper();

            //Construct the multi query for inserting the source and target users into the user table
            $query = "INSERT IGNORE INTO users (username) VALUES ('$source'); ";
            $query .= "INSERT IGNORE INTO users (username) VALUES ('$target')";

            //Execute the multi query
            $mysql->executeQuery($query, true);

            /**
             * SQL query that inserts our new message into the message table.
             * Performs a select and a Left outer Join to get the usernames
             * from the user table based on the source and target parameters
             */
            $query = "INSERT INTO messages (source, target, message)
             SELECT U1.id, U2.id, '$message'
             FROM users U1 
             LEFT OUTER JOIN users U2 on U2.username='$target'
             WHERE U1.username='$source'";

            //Execute our SQL, single query
            $mysql->executeQuery($query, false);

            //Get the id returned from the insert operation
            $id = $mysql->getInsertId();

            /**
             * If the id is > 0, set our content type to json,
             * the return status to 201 and echo the json to the response body
             */
            if($id > 0) {
                header('Content-Type: application/json; charset=utf-8');
                ResponseHelper::setResponseHeaders(201);
                echo "{ 'id' : ".$mysql->getInsertId()." }";
            } else {
                ResponseHelper::setResponseHeaders(500);
            }

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
            $source = empty($_GET["source"]) ? "" : $_GET["source"];
            $target = empty($_GET["target"]) ? "" : $_GET["target"];

            //If our source parameter is not empty, validate what was in it
            if (!$sourceIsEmpty) {
                //If validation fails, return status 400 and break out of our switch statement
                if (!validateString($source)) {
                    ResponseHelper::setResponseHeaders(400);
                    break;
                }
            }

            //If our target parameter is not empty, validate what was in it
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
                $result = $mysql->executeQuery($query, false);

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