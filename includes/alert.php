<?php
if (isset($_SESSION['message'])) {
    $type = "success";
    if (isset($_SESSION['msg_type'])) {
        $type = $_SESSION['msg_type'];
    }
    echo "<div class='alert $type'>" . htmlspecialchars($_SESSION['message']) . "</div>";
    unset($_SESSION['message']);
    unset($_SESSION['msg_type']);
}
?>