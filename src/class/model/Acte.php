<?php

include_once(ROOT."src/class/io/DatabaseIO.php");
include_once(ROOT."src/class/model/Personne.php");
include_once(ROOT."src/class/model/Relation.php");
include_once(ROOT."src/class/model/Condition.php");

class Acte implements DatabaseIO {

  public $id;

  public $contenu;
  public $epoux;
  public $epouse;
  public $temoins;
  public $parrains;
  public $source_id;
  public $date_start;
  public $date_end;
  public $relations;
  public $conditions;

  public function __construct($id = NULL, $contenu = NULL){
    $this->id = $id;
    $this->source_id = SOURCE_DEFAULT_ID;   //  depuis config.php
    $this->contenu = NULL;
    $this->epoux = NULL;
    $this->epouse = NULL;
    $this->temoins = array();
    $this->parrains = array();
    $this->date_start = NULL;
    $this->date_end = NULL;
    $this->conditions = [];
    $this->relations = [];
  }

  public function set_contenu($contenu){
    $this->contenu = $contenu;
  }

  public function set_epoux($epoux){
    $this->epoux = $epoux;
    // var_dump($epoux); /* cf /morgan/epoux.txt pour l'output ***/
  }

  public function set_epouse($epouse){
    $this->epouse = $epouse;
  }

  public function set_date($date){
    $dates = read_date($date);    //  depuis utils.php  ***
    $this->date_start = $dates[0];
    $this->date_end = $dates[1];
  }
  //  add_temoin($temoin) appelée dans XMLActeReader.php ***
  public function add_temoin($temoin){
    $this->temoins[] = $temoin;
    // print_r($temoin); /* cf /morgan/temoin.txt pour l'output ***/
  }
  //  add_parrain($parrain) appelée dans XMLActeReader.php ***
  public function add_parrain($parrain){
    $this->parrains[] = $parrain;
  }

  public function recursive_link_conditions_and_relations($personne){
    global $mysqli;

    if(isset($personne->id) && $personne->is_valid()){    //  depuis Personne.php
      foreach($personne->relations as $relation)
          $mysqli->into_db_acte_has_relation($this, $relation); //  j'en étais là vendredi 210212 ****

      foreach($personne->conditions as $condition)
          $mysqli->into_db_acte_has_condition($this, $condition);

      if(isset($personne->pere))
          $this->recursive_link_conditions_and_relations($personne->pere);
      if(isset($personne->mere))
          $this->recursive_link_conditions_and_relations($personne->mere);
    }
  }

  public function contenu_into_db(){
    global $mysqli;

    $contenu = $mysqli->real_escape_string($this->contenu);
    $values = [
      "acte_id" => $this->id,
      "contenu" => $contenu
    ];

    //  docu ok ***
    //  affiche l'id de l'acte et le contenu
    // echo '<pre>';
    // print_r($values);
    // echo '</pre>';

    return $mysqli->insert(
      "acte_contenu",
      $values,
      " ON DUPLICATE KEY UPDATE contenu='$contenu'");
  }

  public function get_contenu(){
    global $mysqli;
    $result = $mysqli->select("acte_contenu", ["contenu"], "acte_id='$this->id'");
    if($result != FALSE && $result->num_rows == 1){
      $row = $result->fetch_assoc();
      return $row["contenu"];
    }
    return "";
  }

  public function get_date() {
    global $mysqli;
    $mysqli->from_db($this, TRUE, FALSE);
    return $this->date_start;
  }

  // DATABASE IO

  public function get_table_name(){
    return "acte";
  }

  public function get_same_values(){
    return [];
  }

  public function result_from_db($row){
    if($row == NULL)
      return;

    $this->id = $row["id"];
    if(isset($row["epoux"]))
      $this->set_epoux(new Personne($row["epoux"]));
    if(isset($row["epouse"]))
      $this->set_epouse(new Personne($row["epouse"]));
    if(isset($row["date_start"]))
      $this->date_start = $row["date_start"];
    if(isset($row["date_end"]))
      $this->date_end = $row["date_end"];
  }

  public function values_into_db(){
    $values = [];
    if(isset($this->epoux, $this->epoux->id) && $this->epoux->is_valid())    //  *** indiquer d'où vient is_valid()
      $values["epoux"] = $this->epoux->id;
    if(isset($this->epouse, $this->epouse->id) && $this->epouse->is_valid())
      $values["epouse"] = $this->epouse->id;
    if(isset($this->date_start))
      $values["date_start"] = $this->date_start;
    if(isset($this->date_end))
      $values["date_end"] = $this->date_end;
    
    // foreach($values as $value) //  ok
    //   echo '<br>'.$value;   /* affiche l'id de l'époux et celle de l'épouse + la date de l'acte 2x (start et ind) ok ***/
    return $values;
  }

  public function pre_into_db(){
    global $mysqli, $log, $alert;

    if(!isset($this->id)){
      $alert->error("L'acte ne contient pas de num/id");
      $log->e("L'acte ne contient pas de num/id");
      return FALSE;
    }

    $valid_epoux = isset($this->epoux) && $this->epoux->is_valid();
    $valid_epouse = isset($this->epouse) && $this->epouse->is_valid();

    foreach($this->temoins as $temoin){
      if($temoin->is_valid()){
        if($valid_epoux)
          $temoin->add_relation($temoin, $this->epoux, STATUT_TEMOIN);  //  *** indiquer d'où vient STATUT_TEMOIN
        if($valid_epouse)
          $temoin->add_relation($temoin, $this->epouse, STATUT_TEMOIN);
      }
    }

    foreach($this->parrains as $parrain){
      if($parrain->is_valid()){
        if($valid_epoux)
          $parrain->add_relation($parrain, $this->epoux, STATUT_PARRAIN);  //  *** indiquer d'où vient STATUT_PARRAIN
        if($valid_epouse)
          $parrain->add_relation($parrain, $this->epouse, STATUT_PARRAIN);
      }
    }

    if($valid_epoux && $valid_epouse){
      //$this->epouse->add_relation($this->epoux, $this->epouse, STATUT_EPOUX);
      $this->epouse->add_relation($this->epouse, $this->epoux, STATUT_EPOUSE);  //  *** indiquer d'où vient STATUT_EPOUSE
    }

    if(isset($this->epoux))
      $mysqli->into_db($this->epoux);

    if(isset($this->epouse))
      $mysqli->into_db($this->epouse);

    foreach($this->temoins as $temoin)
      $mysqli->into_db($temoin);

    foreach($this->parrains as $parrain)
      $mysqli->into_db($parrain);

    return TRUE;
  }

  public function post_into_db(){
    global $mysqli;

    $personnes = [];
    if(isset($this->epoux))
      $personnes[] = $this->epoux;
    if(isset($this->epouse))
      $personnes[] = $this->epouse;
    $personnes = array_merge($personnes, $this->temoins, $this->parrains);

    $mysqli->start_transaction();
    foreach($personnes as $personne){
      $this->recursive_link_conditions_and_relations($personne);
    }

    $reader = new XMLActeReader($this->source_id);
    $reader->use_xml_text($this->contenu);
    $reader->update_xml($this);

    $this->contenu_into_db();
    $mysqli->end_transaction();
  }

  private function personnes() {
    global $mysqli;
    $personnes = [];

    foreach($this->conditions as $condition)
      $personnes[] = $condition->personne;

    foreach($this->relations as $relation) {
      $personnes[] = $relation->personne_source;
      $personnes[] = $relation->personne_destination;
    }
    return array_unique_by_id($personnes);
  }

  //  PRIVATE METHODS   //

  private function delete_conditions_or_relations($field)
  // 'condition' ou 'relation'
  {
    global $mysqli;

    $test = "acte_id = $this->id";
    $mysqli->delete("acte_has_$field", $test);

    $liste = $field.'s';
    $in = string_list_of_ids($this->{$liste});
    $test = "id in ($in)";
    $mysqli->delete($field, $test);
  }

  private function delete_conditions() {
    $this->delete_conditions_or_relations('condition');
  }

  private function delete_relations() {
    $this->delete_conditions_or_relations('relation');
  }

  private function delete_acte() {
    global $mysqli;

    $mysqli->delete("acte_contenu", "acte_id=$this->id");
    $mysqli->delete("acte", "id=$this->id");
  }

  //  PUBLIC    //

  public function remove_from_db() {
    global $mysqli;

    $mysqli->from_db($this);
    // ^ remplit les champs conditions et relations
    $personnes = $this->personnes();

    $mysqli->start_transaction();
    foreach(['conditions', 'relations'] as $liste)
      if(! empty($this->{$liste}))
      {
        $delete_liste = "delete_$liste";
        $this->{$delete_liste}();
      }
    $this->delete_acte();
    $mysqli->end_transaction();

    $mysqli->purge_personnes($personnes);
  }
}

?>
