<?php

// classe implémentant en statique les méthodes de connexion et de requete
// et générant un objet correspondant à un itérateur permettant d'accéder au résultat
class PgSql implements Iterator {
  static $server; // le nom du serveur
  protected $sql = null; // la requête conservée pour pouvoir faire plusieurs rewind
  protected $result = null; // l'objet retourné par pg_query()
  protected $first; // indique s'il s'agit du premier rewind
  protected $id; // un no en séquence à partir de 1
  protected $ctuple = false; // le tuple courant ou false
  
  static function open(string $connection_string) {
    $pattern = '!^host=([^ ]+)( port=([^ ]+))? dbname=([^ ]+) user=([^ ]+)( password=([^ ]+))?$!';
    if (!preg_match($pattern, $connection_string, $matches))
      throw new Exception("Erreur: dans PgSql::open() params \"".$connection_string."\" incorrect");
    $server = $matches[1];
    $port = $matches[3];
    $database = $matches[4];
    $user = $matches[5];
    $passwd = $matches[7] ?? null;
    self::$server = $server;
    if (!$passwd) {
      if (!is_file(__DIR__.'/secret.inc.php'))
        throw new Exception("Erreur: dans PgSql::open($connection_string), fichier secret.inc.php absent");
      else {
        $secrets = require(__DIR__.'/secret.inc.php');
        $passwd = $secrets['sql']["pgsql://$user@$server".($port ? ":$port" : '')."/"] ?? null;
        if (!$passwd)
          throw new Exception("Erreur: dans PgSql::open($connection_string), mot de passe absent de secret.inc.php");
      }
      $connection_string .= " password=$passwd";
    }
    if (!pg_connect($connection_string))
      throw new Exception('Could not connect: '.pg_last_error());
  }
  
  static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans PgSql::server() server non défini");
    return self::$server;
  }
  
  static function close(): void { pg_close(); }
  
  static function query(string $sql) {
    if (!($result = @pg_query($sql)))
      throw new Exception('Query failed: '.pg_last_error());
    if ($result === TRUE)
      return TRUE;
    else
      return new PgSql($sql, $result);
  }

  function __construct(string $sql, $result) { $this->sql = $sql; $this->result = $result; $this->first = true; }
  
  function rewind(): void {
    if ($this->first) // la première fois ne pas faire de pg_query qui a déjà été fait
      $this->first = false;
    elseif (!($this->result = @pg_query($this->sql)))
      throw new Exception('Query failed: '.pg_last_error());
    $this->id = 0;
    $this->next();
  }
  
  function next(): void {
    $this->ctuple = pg_fetch_array($this->result, null, PGSQL_ASSOC);
    $this->id++;
  }
  
  function valid(): bool { return $this->ctuple <> false; }
  function current(): array { return $this->ctuple; }
  function key(): int { return $this->id; }
};
