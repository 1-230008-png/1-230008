<?php require 'config.php';if(user())audit('Logged out');$_SESSION=[];session_destroy();header('Location: index.php');
