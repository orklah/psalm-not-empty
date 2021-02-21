<?php declare(strict_types=1);

namespace Orklah\NotEmpty\Hooks;

use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Variable;
use Psalm\FileManipulation;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic;
use function get_class;


class NotEmptyHooks implements AfterExpressionAnalysisInterface
{
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        if (!$event->getCodebase()->alter_code) {
            return true;
        }

        $original_expr = $event->getExpr();

        $node_provider = $event->getStatementsSource()->getNodeTypeProvider();
        $comparison_operator = '===';
        $combination_operator = '||';

        if($original_expr instanceof BooleanNot && $original_expr->expr instanceof Empty_){
            $comparison_operator = '!==';
            $combination_operator = '&&';
            $expr = $original_expr->expr;
        } elseif ($original_expr instanceof Empty_) {
            if($event->getContext()->inside_negation){
                //we're inside a negation. If we start messing with replacements now, we won't be able to handle the negation then
                return true;
            }
            $expr = $original_expr;
        } else {
            return true;
        }

        if (!$expr->expr instanceof Variable) {
            return true;
        }

        $type = $node_provider->getType($expr->expr);
        if ($type === null) {
            return true;
        }

        if ($type->from_docblock) {
            //TODO: maybe add an issue in non alter mode
            return true;
        }

        if (!$type->isSingle()) {
            // TODO: add functionnality with isSingleAndMaybeNullable and add || EXPR === null
            return true;
        }

        $atomic_types = $type->getAtomicTypes();
        $atomic_type = array_shift($atomic_types);
        $display_expr = '$' .$expr->expr->name;

        $replacement = null;
        if ($atomic_type instanceof Atomic\TInt) {
            $replacement = $display_expr . ' ' . $comparison_operator . ' ' . '0';
        } elseif ($atomic_type instanceof Atomic\TFloat) {
            $replacement = $display_expr . ' ' . $comparison_operator . ' ' . '0.0';
        } elseif ($atomic_type instanceof Atomic\TString) {
            $replacement = '(';
            $replacement .= $display_expr . ' ' . $comparison_operator . ' ' . "''";
            $replacement .= ' ' . $combination_operator . ' ';
            $replacement .= $display_expr . ' ' . $comparison_operator . ' ' . "'0'";
            $replacement .= ')';
        } elseif ($atomic_type instanceof Atomic\TArray
            || $atomic_type instanceof Atomic\TList
            || $atomic_type instanceof Atomic\TKeyedArray
        ) {
            $replacement = $display_expr . ' ' . $comparison_operator . ' ' . '[]';
        } elseif ($atomic_type instanceof Atomic\TBool) {
            $replacement = $display_expr . ' ' . $comparison_operator . ' ' . 'false';
        } else {
            // object, named objects could be replaced by false(or true if !empty)
            // null could be replace by true (or false if !empty)
            if(!$atomic_type instanceof Atomic\TMixed) {
                var_dump(get_class($atomic_type));
                var_dump($type->getId());
            }
        }

        if($replacement !== null){
            $startPos = $original_expr->getStartFilePos();
            $endPos = $original_expr->getEndFilePos()+1;
            $file_manipulation = new FileManipulation($startPos, $endPos, $replacement);
            $event->addFileReplacements([$file_manipulation]);
        }

        return true;
    }
}
