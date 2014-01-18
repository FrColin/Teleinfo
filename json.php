<?php
setlocale(LC_ALL , "fr_FR" );
date_default_timezone_set("Europe/Paris");
error_reporting(0);

// Adapté du code de Domos.
// cf . http://vesta.homelinux.net/wiki/teleinfo_papp_jpgraph.html

// Config : Connexion MySql et requête. et prix du kWh
include_once("config.php");
include_once("queries.php");

/****************************************/
/*    Graph consommation instantanée    */
/****************************************/
function instantly () {
  global $refresh_auto, $refresh_delay;

  $date = isset($_GET['date'])?$_GET['date']:null;

  $heurecourante = date('H') ;              // Heure courante.
  $timestampheure = mktime($heurecourante+1,0,0,date("m"),date("d"),date("Y"));  // Timestamp courant à heure fixe (mn et s à 0).

  // Meilleure date entre celle donnée en paramètre et celle calculée
  $date = ($date)?min($date, $timestampheure):$timestampheure;

  $periodesecondes = 24*3600 ;                            // 24h.
  $timestampfin = $date;
  $timestampdebut2 = $date - $periodesecondes ;           // Recule de 24h.
  $timestampdebut = $timestampdebut2 - $periodesecondes ; // Recule de 24h.

  $query = queryInstantly();

  $result=mysql_query($query) or die ("<b>Erreur</b> dans la requète <b>" . $query . "</b> : "  . mysql_error() . " !<br>");

  $nbdata=0;
  $nbenreg = mysql_num_rows($result);
  if ($nbenreg > 0) {
    $row = mysql_fetch_array($result);
    $optarif = $row["optarif"];
    $demain = $row["demain"];
    $date_deb = $row["timestamp"];
    $val = floatval(str_replace(",", ".", $row["papp"]));
  };
  mysql_free_result($result);

  $datetext = strftime("%c",$date_deb);

  $seuils = array (
    'min' => 0,
    'max' => 10000,
  );

  // Subtitle pour la période courante
  switch ($optarif) {
    case "BBR":
      $subtitle = "Prochaine période : <b>".$demain."</b>";
      break;
    default :
      $subtitle = "";
      break;
  }

  $instantly = array(
    'title' => "Consommation du $datetext",
    'subtitle' => $subtitle,
    'debut' => $date_deb*1000, // $date_deb_UTC,
    'W_name' => "Watts",
    'W_data'=> $val,
    'seuils' => $seuils,  // non utilisé pour l'instant
    'optarif' => $optarif,
    'demain' => $demain,
    'refresh_auto' => $refresh_auto,
    'refresh_delay' => $refresh_delay
  );

  return $instantly;
}

/****************************************************************************************/
/*    Graph consomation w des 24 dernières heures + en parrallèle consomation d'Hier    */
/****************************************************************************************/
function daily () {
  global $liste_ptec; global $db_ptec_equiv;
  global $chart_colors;

  $optarif = getOpTarif();

  $date = isset($_GET['date'])?$_GET['date']:null;

  $heurecourante = date('H') ;              // Heure courante.
  $timestampheure = mktime($heurecourante+1,0,0,date("m"),date("d"),date("Y"));  // Timestamp courant à heure fixe (mn et s à 0).

  // Meilleure date entre celle donnée en paramètre et celle calculée
  $date = ($date)?min($date, $timestampheure):$timestampheure;

  $periodesecondes = 24*3600 ;                            // 24h.
  $timestampfin = $date;
  $timestampdebut2 = $date - $periodesecondes ;           // Recule de 24h.
  $timestampdebut = $timestampdebut2 - $periodesecondes ; // Recule de 24h.


  $query = queryDaily($timestampdebut, $timestampfin);

  $result=mysql_query($query) or die ("<b>Erreur</b> dans la requète <b>" . $query . "</b> : "  . mysql_error() . " !<br>");

  $nbdata=0;
  $nbenreg = mysql_num_rows($result);
  $nbenreg--;
  $date_deb=0; // date du 1er enregistrement
  $date_fin=time();

  // Initialisation des courbes qui seront affichées
  foreach($liste_ptec[$optarif] as $ptec => $caption){
    $courbe_titre[$ptec]=$caption;
    $courbe_min[$ptec]=5000;
    $courbe_max[$ptec]=0;
    $courbe_mindate[$ptec]=null;
    $courbe_maxdate[$ptec]=null;
    $array[$ptec]=array();
  }
  // Ajout des courbes intensité et PREC
  $courbe_titre["I"]="Intensité";
  $courbe_min["I"]=5000;
  $courbe_max["I"]=0;
  $courbe_mindate["I"]=null;
  $courbe_maxdate["I"]=null;
  $array["I"] = array();
  $courbe_titre["PREC"]="Période précédente";
  $courbe_min["PREC"]=5000;
  $courbe_max["PREC"]=0;
  $courbe_mindate["PTEC"]=null;
  $courbe_maxdate["PTEC"]=null;
  $array["PREC"] = array();

  $navigator = array();

  $row = mysql_fetch_array($result);
  $ts = intval($row["timestamp"]);

  // Période précédente
  while (($ts < $timestampdebut2) && ($nbenreg>0) ){
    $ts = ( $ts + 24*3600 ) * 1000;
    $val = floatval(str_replace(",", ".", $row["papp"]));
    $array["PREC"][] = array($ts, $val); // php recommande cette syntaxe plutôt que array_push
    if ($courbe_max["PREC"] < $val) {$courbe_max["PREC"] = $val; $courbe_maxdate["PREC"] = $ts;};
    if ($courbe_min["PREC"] > $val) {$courbe_min["PREC"] = $val; $courbe_mindate["PREC"] = $ts;};
    $row = mysql_fetch_array($result);
    $ts = intval($row["timestamp"]);
    $nbenreg--;
  }

  // Période courante
  while ($nbenreg > 0 ){
    if ($date_deb==0) {
      $date_deb = $row["timestamp"];
    }
    $ts = intval($row["timestamp"]) * 1000;

    $val = floatval(str_replace(",", ".", $row["papp"]));

    $curptec = $db_ptec_equiv[$row["ptec"]];
    // Affecte la consommation selon la période tarifaire
    foreach($liste_ptec[$optarif] as $ptec => $caption){
      if ($curptec == $ptec) {
        $array[$ptec][] = array($ts, $val); // php recommande cette syntaxe plutôt que array_push
      } else {
        $array[$ptec][] = array($ts, null);
      }
    }
    // Ajuste les seuils min/max le cas échéant
    if ($courbe_max[$curptec] < $val) {$courbe_max[$curptec] = $val; $courbe_maxdate[$curptec] = $ts;};
    if ($courbe_min[$curptec] > $val) {$courbe_min[$curptec] = $val; $courbe_mindate[$curptec] = $ts;};

    // Highstock permet un navigateur chronologique
    $navigator[] = array($ts, $val);

    // Intensité
    $val = floatval(str_replace(",", ".", $row["iinst1"])) ;
    $array["I"][] = array($ts, $val); // php recommande cette syntaxe plutôt que array_push
    if ($courbe_max["I"] < $val) {$courbe_max["I"] = $val; $courbe_maxdate["I"] = $ts;};
    if ($courbe_min["I"] > $val) {$courbe_min["I"] = $val; $courbe_mindate["I"] = $ts;};

    // récupérer prochaine occurence de la table
    $row = mysql_fetch_array($result);
    $nbenreg--;
    $nbdata++;
  }
  mysql_free_result($result);

  $date_fin = $ts/1000;

  $plotlines_max = max(array_diff_key($courbe_max, array("I"=>null, "PREC"=>null)));
  //$plotlines_min = min($courbe_min[0], $courbe_min[1], $courbe_min[2]);
  $plotlines_min = min(array_diff_key($courbe_min, array("I"=>null, "PREC"=>null)));

  /*var_dump($courbe_max);
  var_dump(array_diff_key($courbe_max, array("I"=>null, "PREC"=>null)));
  die;*/

  $ddannee = date("Y",$date_deb);
  $ddmois = date("m",$date_deb);
  $ddjour = date("d",$date_deb);
  $ddheure = date("G",$date_deb); //Heure, au format 24h, sans les zéros initiaux
  $ddminute = date("i",$date_deb);

  $ddannee_fin = date("Y",$date_fin);
  $ddmois_fin = date("m",$date_fin);
  $ddjour_fin = date("d",$date_fin);
  $ddheure_fin = date("G",$date_fin); //Heure, au format 24h, sans les zéros initiaux
  $ddminute_fin = date("i",$date_fin);

  $date_deb_UTC=$date_deb*1000;

  //$datetext = "$ddjour/$ddmois/$ddannee  $ddheure:$ddminute au $ddjour_fin/$ddmois_fin/$ddannee_fin  $ddheure_fin:$ddminute_fin";
  $datetext = "$ddjour/$ddmois  $ddheure:$ddminute au $ddjour_fin/$ddmois_fin  $ddheure_fin:$ddminute_fin";

  $seuils = array (
    'min' => $plotlines_min,
    'max' => $plotlines_max,
  );

  $daily = array(
    'title' => "Graph du $datetext",
    'subtitle' => "",
    'debut' => $timestampfin*1000, // $date_deb_UTC,
    'series' => $liste_ptec[$optarif],
    'MAX_color' => $chart_colors["MAX"],
    'MIN_color' => $chart_colors["MIN"],
    'navigator' => $navigator,
    'seuils' => $seuils,
    'optarif' => $optarif
  );

  // Ajoute les séries
  foreach(array_keys($array) as $ptec) {
    $daily[$ptec."_name"] = $courbe_titre[$ptec]." [".$courbe_min[$ptec]." ~ ".$courbe_max[$ptec]."]";
    $daily[$ptec."_color"] = $chart_colors[$ptec];
    $daily[$ptec."_data"] = $array[$ptec];
  }

  return $daily;
}

/*************************************************************/
/*    Graph cout sur période [8jours|8semaines|8mois|1an]    */
/*************************************************************/
function history() {
  global $liste_ptec;
  global $chart_colors;

  $optarif = getOpTarif();
  $tab_prix = getTarifs($optarif);
  ksort($tab_prix);
  $prix = end($tab_prix);

  $duree = isset($_GET['duree'])?$_GET['duree']:8;
  $periode = isset($_GET['periode'])?$_GET['periode']:"jours";
  $date = isset($_GET['date'])?$_GET['date']:null;

  switch ($periode) {
    case "jours":
      // Calcul de la fin de période courante
      $timestampheure = mktime(0,0,0,date("m"),date("d"),date("Y"));   // Timestamp courant, 0h
      $timestampheure += 24*3600;                                      // Timestamp courant +24h

      // Meilleure date entre celle donnée en paramètre et celle calculée
      $date = ($date)?min($date, $timestampheure):$timestampheure;

      // Périodes
      $periodesecondes = $duree*24*3600;                               // Periode en secondes
      $timestampfin = $date;                                           // Fin de la période
      $timestampdebut2 = $timestampfin - $periodesecondes;             // Début de période active
      $timestampdebut = $timestampdebut2 - $periodesecondes;           // Début de période précédente

      $xlabel = $duree  . " jours";
      $dateformatsql = "%a %e";
      $abonnement = $prix["AboAnnuel"] / 365;
      break;
    case "semaines":
      // Calcul de la fin de période courante
      $timestampheure = mktime(0,0,0,date("m"),date("d"),date("Y"));   // Timestamp courant, 0h
      $timestampheure += 24*3600;                                      // Timestamp courant +24h

      // Meilleure date entre celle donnée en paramètre et celle calculée
      $date = ($date)?min($date, $timestampheure):$timestampheure;

      // Avance d'un jour tant que celui-ci n'est pas un lundi
      while ( date("w", $date) != 1 )
      {
        $date += 24*3600;
      }

      // Périodes
      $timestampfin = $date;                                           // Fin de la période
      $timestampdebut2 = strtotime(date("Y-m-d", $timestampfin) . " -".$duree." week");    // Début de période active
      $timestampdebut = strtotime(date("Y-m-d", $timestampdebut2) . " -".$duree." week"); // Début de période précédente

      $xlabel = $duree . " semaines";
      $dateformatsql = "sem %v (%x)";
      $abonnement = $prix["AboAnnuel"] / 52;
      break;
    case "mois":
      // Calcul de la fin de période courante
      $timestampheure = mktime(0,0,0,date("m"),date("d"),date("Y")); // Timestamp courant, 0h
      //$timestampheure = mktime(0,0,0,date("m")+1,1,date("Y"));     // Mois suivant, 0h

      // Meilleure date entre celle donnée en paramètre et celle calculée
      $date = ($date)?min($date, $timestampheure):$timestampheure;
      $date = mktime(0,0,0,date("m")+1,1,date("Y"));                 // Mois suivant, 0h

      // Périodes
      $timestampfin = $date;                                         // Fin de la période
      $timestampdebut2 = mktime(0,0,0,date("m",$timestampfin)-$duree,1,date("Y",$timestampfin));      // Début de période active
      $timestampdebut = mktime(0,0,0,date("m",$timestampdebut2)-$duree,1,date("Y",$timestampdebut2)); // Début de période précédente

      $xlabel = $duree . " mois";
      $dateformatsql = "%b (%Y)";
      if ($duree > 6) $dateformatsql = "%b %Y";
      $abonnement = $prix["AboAnnuel"] / 12;
      break;
    case "ans":
      // Calcul de la fin de période courante
      $timestampheure = mktime(0,0,0,date("m"),date("d"),date("Y"));         // Timestamp courant, 0h

      // Meilleure date entre celle donnée en paramètre et celle calculée
      $date = ($date)?min($date, $timestampheure):$timestampheure;
      $date = mktime(0,0,0,1,1,date("Y", $date)+1);                          // Année suivante, 0h

      // Périodes
      $timestampfin = $date;                                                 // Fin de la période
      $timestampdebut2 = mktime(0,0,0,1,1,date("Y",$timestampfin)-$duree);   // Début de période active
      $timestampdebut = mktime(0,0,0,1,1,date("Y",$timestampdebut2)-$duree); // Début de période précédente

      $xlabel = $duree . " an";
      //$xlabel = "l'année ".(date("Y",$timestampdebut2)-$duree)." et ".(date("Y",$timestampfin)-$duree);
      $dateformatsql = "%b %Y";
      $abonnement = $prix["AboAnnuel"] / 12;
      break;
    default:
      die("Periode erronée, valeurs possibles: [8jours|8semaines|8mois|1an] !");
      break;
  }

  $query="SET lc_time_names = 'fr_FR'" ;  // Pour afficher date en français dans MySql.
  mysql_query($query);

  $query = queryHistory($timestampdebut, $dateformatsql, $timestampfin);

  $result=mysql_query($query) or die ("<b>Erreur</b> dans la requète <b>" . $query . "</b> : "  . mysql_error() . " !<br>");
  $nbenreg = mysql_num_rows($result);
  $nbenreg--;
  $kwhprec = array();
  $kwhprec_detail = array();
  $date_deb=0; // date du 1er enregistrement
  $date_fin=time();

  // On initialise à vide
  // Cas si la période précédente est "nulle", on n'aura pas d'initialisation du tableau
  foreach($liste_ptec[$optarif] as $ptec => $caption){
    $kwhp[$ptec] = [];
  }

  // Calcul des consommations
  while ($row = mysql_fetch_array($result))
  {
    $ts = intval($row["timestamp"]);
    if ($ts < $timestampdebut2) {
      // Période précédente
      $cumul = null; // reset (sinon on cumule à chaque étape de la boucle)
      foreach($liste_ptec[$optarif] as $ptec => $caption){
        // On conserve le détail (qui sera affiché en infobulle)
        $kwhp[$ptec][] = floatval(isset($row[$ptec]) ? $row[$ptec] : 0);
        // On calcule le total consommé (qui sera affiché en courbe)
        $cumul[] = isset($row[$ptec]) ? $row[$ptec] : 0;
      }
      $kwhprec[] = array($row["periode"], array_sum($cumul)); // php recommande cette syntaxe plutôt que array_push
    }
    else {
      // Période courante
      if ($date_deb==0) {
        $date_deb = strtotime($row["rec_date"]);
      }
      // Ajout les éléments actuels à chaque tableau
      $rdate[] = $row["rec_date"];
      $timestp[] = $row["periode"];
      foreach($liste_ptec[$optarif] as $ptec => $caption){
        $kwh[$ptec][] = floatval(isset($row[$ptec]) ? $row[$ptec] : 0);
      }
    }
  }

  // On vérifie la durée de la période actuelle
  if (count($kwh) < $duree) {
    // pad avec une valeur négative, pour ajouter en début de tableau
    $timestp = array_pad ($timestp, -$duree, null);
    foreach($kwh as &$current){
      $current = array_pad ($current, -$duree, null);
    }
  }

  // On vérifie la durée de la période précédente
  if (count($kwhprec) < count(reset($kwh))) {
    // pad avec une valeur négative, pour ajouter en début de tableau
    $kwhprec = array_pad ($kwhprec, -count(reset($kwh)), null);
    foreach($kwhp as &$current){
      $current = array_pad ($current, -count(reset($kwh)), null);
    }
  }
  $date_digits_dernier_releve=explode("-", $rdate[count($rdate) -1]) ;
  $date_dernier_releve =  Date('d/m/Y', gmmktime(0,0,0, $date_digits_dernier_releve[1] ,$date_digits_dernier_releve[2], $date_digits_dernier_releve[0])) ;

  mysql_free_result($result);

  $ddannee = date("Y",$date_deb);
  $ddmois = date("m",$date_deb);
  $ddjour = date("d",$date_deb);
  $ddheure = date("G",$date_deb); //Heure, au format 24h, sans les zéros initiaux
  $ddminute = date("i",$date_deb);

  $date_deb_UTC=$date_deb*1000;

  $datetext = "$ddjour/$ddmois/$ddannee  $ddheure:$ddminute";
  $ddmois=$ddmois-1; // nécessaire pour Date.UTC() en javascript qui a le mois de 0 à 11 !!!

  // Calcul des consommations
  foreach($liste_ptec[$optarif] as $ptec => $caption){
    $mnt_kwh[$ptec] = 0;
    $total_kwh[$ptec] = 0;
    $mnt_kwhp[$ptec] = 0;
    $total_kwhp[$ptec] = 0;
  }

  $mnt_abo = 0;
  $mnt_abop = 0;
  $i = 0;

  while ($i < count(reset($kwh))) {
    foreach($liste_ptec[$optarif] as $ptec => $caption) {
      $mnt_kwh[$ptec] += $kwh[$ptec][$i] * $prix["periode"][strtoupper($ptec)];
      $total_kwh[$ptec] += $kwh[$ptec][$i];

      $mnt_kwhp[$ptec] += $kwhp[$ptec][$i] * $prix["periode"][strtoupper($ptec)];
      $total_kwhp[$ptec] += $kwhp[$ptec][$i];
    }
    $mnt_abo += $abonnement;
    $mnt_abop += $abonnement;
    $i++ ;
  }
  $mnt_total = $mnt_abo + array_sum($mnt_kwh);
  $mnt_totalp = $mnt_abop + array_sum($mnt_kwhp);

  /* Prix à retourner */
  $prix = $prix["periode"];
  $prix["abonnement"] = $abonnement;

  // Subtitle pour la période courante
  $subtitle = "<b>Coût sur la période</b> ".round($mnt_total,2)." Euro (".array_sum($total_kwh)." KWh)<br />";
  $subtitle = $subtitle."(Abonnement : ".round($mnt_abo,2);
  foreach($liste_ptec[$optarif] as $ptec => $caption) {
    if ($mnt_kwh[$ptec] != 0) {
      $subtitle = $subtitle." + ".$ptec." : ".round($mnt_kwh[$ptec],2);
    }
  }
  $subtitle = $subtitle.")<br /><b>Total KWh</b> ";

  $prefix = "";
  foreach($liste_ptec[$optarif] as $ptec => $caption) {
    if ($total_kwh[$ptec] != 0) {
      $subtitle = $subtitle.$prefix.$ptec." : ".$total_kwh[$ptec];
      if ($prefix=="") {
        $prefix = " + ";
      }
    }
  }

  // Subtitle pour la période courante
  $subtitle = "<b>Coût sur la période</b> ".round($mnt_total,2)." Euro (".array_sum($total_kwh)." KWh)<br />";
  $subtitle = $subtitle."(Abonnement : ".round($mnt_abo,2);
  foreach($liste_ptec[$optarif] as $ptec => $caption) {
    if ($mnt_kwh[$ptec] != 0) {
      $subtitle = $subtitle." + ".$ptec." : ".round($mnt_kwh[$ptec],2);
    }
  }
  $subtitle = $subtitle.")";
  if ((count($liste_ptec[$optarif]) > 1) && (array_sum($total_kwh) > 0)) {
    $subtitle = $subtitle."<br /><b>Total KWh</b> ";
    $prefix = "";
    foreach($liste_ptec[$optarif] as $ptec => $caption) {
      if ($total_kwh[$ptec] != 0) {
        $subtitle = $subtitle.$prefix.$ptec." : ".$total_kwh[$ptec];
        if ($prefix=="") {
          $prefix = " + ";
        }
      }
    }
  }

  // Subtitle pour la période précédente
  $subtitle = $subtitle."<br /><b>Coût sur la période précédente</b> ".round($mnt_totalp,2)." Euro (".array_sum($total_kwhp)." KWh)<br />";
  $subtitle = $subtitle."(Abonnement : ".round($mnt_abo,2);
  foreach($liste_ptec[$optarif] as $ptec => $caption) {
    if ($mnt_kwhp[$ptec] != 0) {
      $subtitle = $subtitle." + ".$ptec." : ".round($mnt_kwhp[$ptec],2);
    }
  }
  $subtitle = $subtitle.")";
  if ((count($liste_ptec[$optarif]) > 1) && (array_sum($total_kwhp) > 0)) {
    $subtitle = $subtitle."<br /><b>Total KWh</b> ";
    $prefix = "";
    foreach($liste_ptec[$optarif] as $ptec => $caption) {
      if ($total_kwhp[$ptec] != 0) {
        $subtitle = $subtitle.$prefix.$ptec." : ".$total_kwhp[$ptec];
        if ($prefix=="") {
          $prefix = " + ";
        }
      }
    }
  }

  $history = array(
    'title' => "Consomation sur $xlabel",
    'subtitle' => $subtitle,
    'duree' => $duree,
    'periode' => $periode,
    'debut' => $timestampfin*1000,
    'optarif' => $optarif,
    'series' => $liste_ptec[$optarif],
    'prix' => $prix,
    'categories' => $timestp,
    'PREC_color' => $chart_colors["PREC"],
    'PREC_name' => 'Période Précédente',
    'PREC_data' => $kwhprec,
    'PREC_data_detail' => $kwhp
  );

  // Ajoute les séries
  foreach($liste_ptec[$optarif] as $ptec => $caption) {
    $history[$ptec."_color"] = $chart_colors[$ptec];
    $history[$ptec."_data"] = $kwh[$ptec];
  }

  return $history;
}

function main() {
    global $db_connect;

    $query = isset($_GET['query'])?$_GET['query']:"daily";

    if (isset($query)) {
        mysql_connect($db_connect['serveur'], $db_connect['login'], $db_connect['pass']) or die("Erreur de connexion au serveur MySql");
        mysql_select_db($db_connect['base']) or die("Erreur de connexion a la base de donnees $base");
        mysql_query("SET NAMES 'utf8'");

        switch ($query) {
        case "instantly":
            $data=instantly();
            break;
        case "daily":
            $data=daily();
            break;
        case "history":
            $data=history();
            break;
        default:
            break;
      };
      mysql_close() ;

      echo json_encode($data);
    }
}

main();

?>
