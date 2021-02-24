<?php declare(strict_types=1);

namespace Orklah\NotEmpty\Hooks;

use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Variable;
use Psalm\FileManipulation;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic;
use function count;
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

        if ($original_expr instanceof BooleanNot && $original_expr->expr instanceof Empty_) {
            $comparison_operator = '!==';
            $combination_operator = '&&';
            $expr = $original_expr->expr;
        } elseif ($original_expr instanceof Empty_) {
            if ($event->getContext()->inside_negation) {
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

        $display_expr = '$' . $expr->expr->name;

        $replacement = [];
        if ($type->isSingleAndMaybeNullable() && $type->isNullable()) {
            $replacement[] = $display_expr . ' ' . $comparison_operator . ' ' . 'null';
            $type->removeType('null');
        }

        if(!$type->isSingle()){
            //we removed null but the type is still not single
            return true;
        }

        $atomic_types = $type->getAtomicTypes();
        $atomic_type = array_shift($atomic_types);
        if ($atomic_type instanceof Atomic\TInt) {
            $replacement[] = $display_expr . ' ' . $comparison_operator . ' ' . '0';
        } elseif ($atomic_type instanceof Atomic\TFloat) {
            $replacement[] = $display_expr . ' ' . $comparison_operator . ' ' . '0.0';
        } elseif ($atomic_type instanceof Atomic\TString) {
            $replacement[] = $display_expr . ' ' . $comparison_operator . ' ' . "''";
            $replacement[] = $display_expr . ' ' . $comparison_operator . ' ' . "'0'";
        } elseif ($atomic_type instanceof Atomic\TArray
            || $atomic_type instanceof Atomic\TList
            || $atomic_type instanceof Atomic\TKeyedArray
        ) {
            $replacement[] = $display_expr . ' ' . $comparison_operator . ' ' . '[]';
        } elseif ($atomic_type instanceof Atomic\TBool) {
            $replacement[] = $display_expr . ' ' . $comparison_operator . ' ' . 'false';
        } elseif ($atomic_type instanceof Atomic\TObject || $atomic_type instanceof Atomic\TNamedObject) {
            if($comparison_operator === '===') {
                if ($combination_operator === '&&') { //don't add || false, it would be stupid
                    $replacement[] = 'false';
                }
            }
            else{
                if ($combination_operator === '||') { //don't add && true, it would be stupid
                    $replacement[] = 'true';
                }
            }
        } elseif ($atomic_type instanceof Atomic\TNull) {
            if ($combination_operator === '||') { //don't add && true, it would be stupid
                $replacement[] = 'true';
            }
        } else {
            return true;
        }

        if ($replacement !== []) {
            $replacement = array_unique($replacement); // deduplicate some conditions (null from type and from maybe nullable)
            if (count($replacement) > 2) {
                //at one point, you have to ask if empty is not the best thing to use!
                return true;
            }

            $replacement_string = implode(' ' . $combination_operator . ' ', $replacement);
            if (count($replacement) > 1) {
                $replacement_string = '(' . $replacement_string . ')';
            }

            $startPos = $original_expr->getStartFilePos();
            $endPos = $original_expr->getEndFilePos() + 1;
            $file_manipulation = new FileManipulation($startPos, $endPos, $replacement_string);
            $event->setFileReplacements([$file_manipulation]);
        }

        return true;
    }
}
