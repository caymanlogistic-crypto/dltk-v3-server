<?php
declare(strict_types=1);

function workspace_trip_supports_formula(string $table,string $column): bool {
    return $table==='vyvoz' && in_array($column,['J','X'],true);
}

/** Use exact, bounded fractions so that division does not introduce rounding into the saved number. */
final class WorkspaceTripFormula {
    private int $offset=1;

    private function __construct(private readonly string $expression) {}

    public static function evaluate(string $expression): string {
        $expression=trim(str_replace(["\u{00A0}","\u{202F}",'−'],[' ',' ','-'],$expression));
        if (strlen($expression)>256 || !preg_match('/^=[0-9.,+*\/() \t-]+$/D',$expression)) {
            self::invalid();
        }
        $parser=new self($expression);
        [$numerator,$denominator]=$parser->sum();
        if ($parser->peek()!=='') self::invalid();
        if ($denominator!==1) {
            throw new ApiError('formula_fraction','Результат формулы должен быть целым числом.');
        }
        return (string)$numerator;
    }

    private function peek(): string {
        $this->offset+=strspn($this->expression," \t",$this->offset);
        return $this->expression[$this->offset]??'';
    }

    private function sum(): array {
        [$left,$leftDivisor]=$this->product();
        while (in_array($operator=$this->peek(),['+','-'],true)) {
            $this->offset++;
            [$right,$rightDivisor]=$this->product();
            $gcd=self::gcd($leftDivisor,$rightDivisor);
            $leftFactor=intdiv($rightDivisor,$gcd);
            $rightFactor=intdiv($leftDivisor,$gcd);
            $lhs=self::multiply($left,$leftFactor);
            $rhs=self::multiply($operator==='-' ? -$right : $right,$rightFactor);
            if (($lhs>0 && $rhs>0 || $lhs<0 && $rhs<0) && abs($lhs)>PHP_INT_MAX-abs($rhs)) self::overflow();
            [$left,$leftDivisor]=self::reduce($lhs+$rhs,self::multiply($leftDivisor,$leftFactor));
        }
        return [$left,$leftDivisor];
    }

    private function product(): array {
        [$left,$leftDivisor]=$this->operand();
        while (in_array($operator=$this->peek(),['*','/'],true)) {
            $this->offset++;
            [$right,$rightDivisor]=$this->operand();
            if ($operator==='/') {
                if ($right===0) throw new ApiError('formula_division','В формуле нельзя делить на ноль.');
                [$right,$rightDivisor]=[$right<0 ? -$rightDivisor : $rightDivisor,abs($right)];
            }
            $leftGcd=self::gcd(abs($left),$rightDivisor);
            $rightGcd=self::gcd(abs($right),$leftDivisor);
            $left=self::multiply(intdiv($left,$leftGcd),intdiv($right,$rightGcd));
            $leftDivisor=self::multiply(intdiv($leftDivisor,$rightGcd),intdiv($rightDivisor,$leftGcd));
        }
        return [$left,$leftDivisor];
    }

    private function operand(): array {
        $sign=1;
        while (in_array($operator=$this->peek(),['+','-'],true)) {
            if ($operator==='-') $sign=-$sign;
            $this->offset++;
        }
        if ($this->peek()==='(') {
            $this->offset++;
            [$value,$divisor]=$this->sum();
            if ($this->peek()!==')') self::invalid();
            $this->offset++;
            return [$sign*$value,$divisor];
        }
        if (!preg_match('/\G([0-9]+)(?:[.,]([0-9]+))?/',$this->expression,$number,0,$this->offset)) {
            self::invalid();
        }
        $this->offset+=strlen($number[0]);
        $fraction=rtrim($number[2]??'','0');
        $digits=ltrim($number[1].$fraction,'0');
        if (strlen($digits)>18 || strlen($fraction)>18) self::overflow();
        return self::reduce($sign*(int)$digits,10**strlen($fraction));
    }

    private static function gcd(int $left,int $right): int {
        while ($right!==0) [$left,$right]=[$right,$left%$right];
        return $left;
    }

    private static function reduce(int $numerator,int $denominator): array {
        $gcd=self::gcd(abs($numerator),$denominator);
        return [intdiv($numerator,$gcd),intdiv($denominator,$gcd)];
    }

    private static function multiply(int $left,int $right): int {
        if ($right!==0 && abs($left)>intdiv(PHP_INT_MAX,abs($right))) self::overflow();
        return $left*$right;
    }

    private static function overflow(): never {
        throw new ApiError('formula_overflow','Числа в формуле слишком велики для точного вычисления.');
    }

    private static function invalid(): never {
        throw new ApiError('invalid_formula','Введите формулу с числами, скобками и знаками +, −, * или / после =.');
    }
}
