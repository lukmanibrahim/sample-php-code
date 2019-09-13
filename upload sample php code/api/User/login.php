<?php
 
// include database and object files
include_once '../config/database.php';
include_once '../objects/user.php';
 
// get database connection
$database = new Database();
$db = $database->getConnection();
 
// prepare user object
$user = new User($db);

// get posted data
$data = json_decode(file_get_contents("php://input"));


// set ID property of user to be edited
$user->username = $data->username;
// $user->password = $data->password;
// $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
// $user->password_hash = password_hash($data->password, PASSWORD_BCRYPT);

$user->password = base64_encode($data->password);

// read the details of user to be edited

$stmt = $user->login();
if($stmt->rowCount() > 0){
    // get retrieved row
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // create array
    $user_arr=array(
        "status" => true,
        "message" => "Successfully Login!",
        "id" => $row['id'],
        "username" => $row['username']
    );
}
else{
    $user_arr=array(
        "status" => false,
        "message" => "Invalid Username or Password!",
    );
}
// make it json format
print_r(json_encode($user_arr));
?>