<?php
    require_once(dirname(__FILE__)."/../../lib/private/connector.class.php");
    require_once(dirname(__FILE__)."/../../lib/config/config.php");
    require_once(dirname(__FILE__)."/../../lib/private/gameconfig.php");
    require_once("tools_install.php");
    
    // globals

    $RoleKeys = array_keys($gRoles);
    $g_RoleToIdx = array();
    $RoleIdx = 0;
    
    foreach($RoleKeys as $Key)
    {
        $g_RoleToIdx[$Key] = $RoleIdx++;
    }
    
    $Out = Out::getInstance();
    $Connector = Connector::getInstance();
    
    // -------------------------------------------------------------------------
    //  Database
    // -------------------------------------------------------------------------
    
    echo "<div class=\"update_version\">".L("EnsureValidDatabase");
    
    InstallDB(RP_TABLE_PREFIX);
    InstallDefaultSettings(RP_TABLE_PREFIX);
    
    $Out->clear(); // TODO: Transform to HTML
    
    echo "<div class=\"update_step_ok\">".L("Ok")."</div>";        
    echo "</div>";
    
    // -------------------------------------------------------------------------
    //  Invalid characters
    // -------------------------------------------------------------------------
    
    echo "<div class=\"update_version\">".L("InvalidCharacters");
    
    $CharStatement = $Connector->prepare("SELECT CharacterId, Name, Class, Role1, Role2 FROM `".RP_TABLE_PREFIX."Character`");
    $FixCount = 0;
    $ResolveCount = 0;
        
    if (!$CharStatement->execute())
    {
        postHTMLErrorMessage( $CharStatement );
    }
    else
    {
        $InvalidCharacters = array();
        
        while ( $Character = $CharStatement->fetch(PDO::FETCH_ASSOC) )
        {
            $CharId = intval($Character["CharacterId"]);
            $SubmitNewRole = false;
            
            if ( !array_key_exists($Character["Class"], $gClasses) )
            {
                array_push( $InvalidCharacters, $Character );
            }
            else
            {            
                $MainRoleOk = false;
                $OffRoleOk  = false;
                $Roles      = $gClasses[$Character["Class"]][2];
                
                // Check if roles are allowed for the class
                
                foreach ( $Roles as $Role )
                {
                    $MainRoleOk = $MainRoleOk || ($g_RoleToIdx[$Role] == intval($Character["Role1"]));
                    $OffRoleOk  = $OffRoleOk  || ($g_RoleToIdx[$Role] == intval($Character["Role2"]));
                }
                
                // Fix main role if necessary
                
                if ( !$MainRoleOk )
                {
                    $Character["Role1"] = ($OffRoleOk) 
                        ? $Character["Role2"]
                        : $g_RoleToIdx[ $Roles[0] ];
                }
                
                // Fix off role if necessary
                
                if ( !$OffRoleOk )
                {
                    $Character["Role2"] = ($MainRoleOk) 
                        ? $Character["Role1"]
                        : $g_RoleToIdx[ $Roles[0] ];
                }
                
                if ( !$MainRoleOk || !$OffRoleOk )
                {
                    // Update roles
                
                    $UpdateChar = $Connector->prepare("UPDATE `".RP_TABLE_PREFIX."Character` SET Role1=:Role1, Role2=:Role2 WHERE CharacterId=:CharId");
                    $UpdateChar->bindValue(":Role1", $Character["Role1"], PDO::PARAM_INT);
                    $UpdateChar->bindValue(":Role2", $Character["Role2"], PDO::PARAM_INT);
                    $UpdateChar->bindValue(":CharId", $Character["CharacterId"], PDO::PARAM_INT);
                    
                    if (!$UpdateChar->execute())
                    {
                        postHTMLErrorMessage( $UpdateChar );
                    }
                    else
                    {
                        ++$FixCount;
                    }
                    
                    // Delete attendances with invalid roles
                    
                    $UpdateChar->closeCursor();
                    
                    $DeleteAttendance = $Connector->prepare("DELETE FROM `".RP_TABLE_PREFIX."Attendance` WHERE CharacterId=:CharId AND Role!=:Role1 AND Role!=:Role2");
                    $DeleteAttendance->bindValue(":Role1", $Character["Role1"], PDO::PARAM_INT);
                    $DeleteAttendance->bindValue(":Role2", $Character["Role2"], PDO::PARAM_INT);
                    $DeleteAttendance->bindValue(":CharId", $Character["CharacterId"], PDO::PARAM_INT);
                    
                    if (!$DeleteAttendance->execute())
                    {
                        postHTMLErrorMessage( $DeleteAttendance );
                    }
                    
                    $DeleteAttendance->closeCursor();
                }
            }
        }
        
        $ResolveCount = sizeof($InvalidCharacters);
        
        foreach( $InvalidCharacters as $Character )
        {
            echo "<div class=\"update_step_warning classResolve\">".$Character["Name"];
            echo "<div class=\"resolve_options\">";
            echo "<span class=\"resolve_type\">".$Character["Class"]."</span>";
            echo "<select id=\"char".$Character["CharacterId"]."\" class=\"change_class\">";
            echo "<option value=\"_delete\">".L("Delete")."</option>";
            
            while(list($Class,$Info) = each($gClasses))
            {
                if ( $Class != "empty" )
                    echo "<option value=\"".$Class."\">".$Info[0]."</option>";
            }
            
            reset($gClasses);
            
            echo "</select>";
            echo "</div>";
            echo "</div>";
        }
    }
    
    if ($ResolveCount > 0)
    {
        echo "<div class=\"update_step_warning classResolve\">".$ResolveCount." ".L("ItemsToResolve");
        echo "<div class=\"resolve_options\">";
        echo "<button id=\"resolveClasses\" onclick=\"resolveClasses()\">".L("Resolve")."</button>";
        echo "</div>";
        echo "</div>";
    }
    else
    {
        echo "<div class=\"update_step_ok\">".$ResolveCount." ".L("ItemsToResolve")."</div>";
    }
    
    if ($FixCount > 0)
        echo "<div class=\"update_step_warning\">".$FixCount." ".L("ItemsRepaired")."</div>";
    else
        echo "<div class=\"update_step_ok\">".$FixCount." ".L("ItemsRepaired")."</div>";
        
    $CharStatement->closeCursor();
    echo "</div>";
    
    // -------------------------------------------------------------------------
    //  Invalid characters
    // -------------------------------------------------------------------------
    
    echo "<div class=\"update_version\">".L("InvalidAttendances");
    
    // Delete attended roles that are out of range
    
    $DeleteAttendance = $Connector->prepare("DELETE FROM `".RP_TABLE_PREFIX."Attendance` WHERE Role>:MaxRole");
    $DeleteAttendance->bindValue(":MaxRole", sizeof($gRoles), PDO::PARAM_INT);
    
    if (!$DeleteAttendance->execute())
    {
        postHTMLErrorMessage( $DeleteAttendance );
    }
    else
    {
        echo "<div class=\"update_step_ok\">".$DeleteAttendance->rowCount()." ".L("ItemsRepaired")." (".L("StrayRoles").")</div>";
    }
    
    $DeleteAttendance->closeCursor();
    
    // Delete stray attends from deleted characters
    
    $DeleteAttendance = $Connector->prepare("DELETE `".RP_TABLE_PREFIX."Attendance`.* FROM `".RP_TABLE_PREFIX."Attendance` ".
                                            "LEFT JOIN `".RP_TABLE_PREFIX."Character` USING(CharacterId) ".
                                            "WHERE `".RP_TABLE_PREFIX."Character`.UserId IS NULL");
    
    if (!$DeleteAttendance->execute())
    {
        postHTMLErrorMessage( $DeleteAttendance );
    }
    else
    {
        echo "<div class=\"update_step_ok\">".$DeleteAttendance->rowCount()." ".L("ItemsRepaired")." (".L("StrayCharacters").")</div>";
    }
    
    $DeleteAttendance->closeCursor();
    
    // Delete stray attends from deleted users
    
    $DeleteAttendance = $Connector->prepare("DELETE `".RP_TABLE_PREFIX."Attendance`.* FROM `".RP_TABLE_PREFIX."Attendance` ".
                                            "LEFT JOIN `".RP_TABLE_PREFIX."User` USING(UserId) ".
                                            "WHERE `".RP_TABLE_PREFIX."User`.UserId IS NULL");
    
    if (!$DeleteAttendance->execute())
    {
        postHTMLErrorMessage( $DeleteAttendance );
    }
    else
    {
        echo "<div class=\"update_step_ok\">".$DeleteAttendance->rowCount()." ".L("ItemsRepaired")." (".L("StrayUsers").")</div>";
    }
    
    $DeleteAttendance->closeCursor();
    
    // Convert users with a cleared binding to local users
    
    {
        require_once(dirname(__FILE__)."/../../lib/private/userproxy.class.php");
        
        $UserSt = $Connector->prepare("Select * FROM `".RP_TABLE_PREFIX."User` WHERE ExternalBinding = '' OR ExternalBinding = NULL");
        
        if (!$UserSt->execute())
        {
            postHTMLErrorMessage( $UserSt );
        }
        else
        {
            while($UserData = $UserSt->fetch(PDO::FETCH_ASSOC))
            {                
                $BindingName = "none";
                
                if ($UserData["ExternalId"] != 0)
                {
                    $Candidates = UserProxy::getAllUserInfosById($UserData["ExternalId"]);
            
                    if ( sizeof($Candidates) > 1 )
                    {
                        // More than one binding, check the username and
                        // reduce the array to username matches
                        
                        $Filtered = array();
                        
                        while( list($BindingName, $UserInfo) = each($Candidates) )
                        {
                            if ( $UserInfo->UserName == $UserData["Login"] )
                            {
                                $Filtered[$BindingName] = $UserInfo;
                            }
                        }
                        
                        // If filtering was successfull, switch arrays
                        
                        if ( sizeof($Filtered) > 0 )
                            $Candidates = $Filtered;
                        else
                            reset($Candidates);
                    }
                    
                    if ( sizeof($Candidates) > 0 )
                        list($BindingName, $UserInfo) = each($Candidates); // fetch the first entry
                }
                
                $UpdateSt = $Connector->prepare("UPDATE `".RP_TABLE_PREFIX."User` SET ExternalBinding=:Binding WHERE UserId=:UserId LIMIT 1");
                $UpdateSt->bindValue(":UserId", $UserData["UserId"], PDO::PARAM_INT);
                $UpdateSt->bindValue(":Binding",$BindingName, PDO::PARAM_STR);
                
                if (!$UpdateSt->execute())
                    postHTMLErrorMessage($UpdateSt);
                    
                $UpdateSt->closeCursor();
            }
            
            echo "<div class=\"update_step_ok\">".$UserSt->rowCount()." ".L("ItemsRepaired")." (".L("StrayBindings").")</div>";
        }
        
        $UserSt->closeCursor();
    }
                    
    echo "</div>";

?>