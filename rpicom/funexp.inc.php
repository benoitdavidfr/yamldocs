<?php
// Définit le concept d'expression fonctionnelle
// ex: 89344@1972-12-01 = 89344@1977-01-01 + 89220 + 89254 + 89352
// l'objectif est d'exprimer chaque version historique par une expression fonctionnelle fondée sur les versions actuelles ou finales

class FunExp {
  static protected $all = []; // [id => FunExp]
  protected $left; // FunExp | Literal
  protected $op; // une des opérations ou fonction élémentaire
  protected $right; // FunExp | Literal
  
  static function union($left, $right): FunExp {
    return new self($left, '+', $right);
  }
  
  static function diff($left, $right): FunExp {
    return new self($left, '-', $right);
  }
  
  static function create(string $funexp): FunExp {
    //echo "create('$funexp')\n";
    if (preg_match('!^([^ ]+) = !', $funexp, $matches)) {
      //print_r($matches);
      return self::set($matches[1], self::create(substr($funexp, strlen($matches[0]))));
    }
    if (preg_match('!^([^ ()]+) (\+|-|\*) ([^ ]+)$!', $funexp, $matches)) {
      //print_r($matches);
      return new self($matches[1], $matches[2], $matches[3]);
    }
    if (preg_match('!^([^ ()]+) (\+|-|\*) !', $funexp, $matches)) {
      //print_r($matches);
      return new self($matches[1], $matches[2], self::create(substr($funexp, strlen($matches[0]))));
    }
    if ($funexp[0] == '(') {
      //echo "sous-expression\n";
      $nbimb = 0;
      for ($pos=1; $pos < strlen($funexp); $pos++) {
        $char = substr($funexp, $pos, 1);
        //echo "char=$char\n";
        if ($char == '(') $nbimb++;
        if ($char == ')') {
          if ($nbimb == 0) {
            //echo "break\n";
            break;
          }
          else
            $nbimb--;
        }
      }
      $ssexp = substr($funexp, 1, $pos-1);
      $pos++; // je passe la )
      //echo "pos=$pos\n";
      if (strlen($funexp) <= $pos) {
        //echo "pas de reste\n";
        return self::create($ssexp);
      }
      while ($funexp[$pos] == ' ') $pos++; // je passe les blancs
      $op = $funexp[$pos]; $pos++;
      while ($funexp[$pos] == ' ') $pos++; // je passe les blancs
      $reste = substr($funexp, $pos);
      //echo "ssexpr = '$ssexp', reste: '$reste'\n";
      //echo "return new FunExp('$ssexp', '$op', '$reste')\n";
      return new self(self::create($ssexp), $op, self::create($reste));
    }
    throw new Exception("No match dans FunExp::create pour \"$funexp\"");
  }

  static function set(string $id, FunExp $fe): FunExp {
    self::$all[$id] = $fe;
    return $fe;
  }
  
  static function show(): void {
    foreach (self::$all as $id => $fe) {
      echo "$id: $fe\n";
    }
  }
  
  static function is($funExp): bool {
    return is_a($funExp, 'FunExp');
  }
  
  // $left et $right sont chacun soit un FunExp soit un string
  function __construct($left, string $op, $right) {
    if (!is_string($left) && !FunExp::is($left))
      throw new Exception("Erreur dans FunExp::__construct() sur left");
    if (!is_string($right) && !FunExp::is($right))
      throw new Exception("Erreur dans FunExp::__construct() sur right");
    $this->left = $left;
    $this->op = $op;
    $this->right = $right;
  }
  
  function __toString(): string {
    return '('.$this->left.' '.$this->op.' '.$this->right.')';
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>funexp</title></head><body><pre>\n";

// ex: 89344@1972-12-01 = 89344@1977-01-01 + 89220 + 89254 + 89352

//echo FunExp::union('89344@1977-01-01', FunExp::diff('89220', FunExp::union('89254', '89352'))),"\n";
//FunExp::set('89344@1972-12-01', FunExp::union('89344@1977-01-01', FunExp::union('89220', FunExp::union('89254', '89352'))));
FunExp::create('89344@1972-12-01 = 89344@1977-01-01 + 89220 + ((xxx + xx) * (yyy * zzz)) - 89254 + 89352');
FunExp::show();