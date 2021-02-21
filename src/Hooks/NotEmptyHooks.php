<?php declare(strict_types=1);

namespace Orklah\NotEmpty\Hooks;

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

        $expr = $event->getExpr();
        $node_provider = $event->getStatementsSource()->getNodeTypeProvider();

        if (!$expr instanceof Empty_) {
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
            $replacement = $display_expr . " === 0";
        } elseif ($atomic_type instanceof Atomic\TFloat) {
            $replacement = $display_expr . " === 0.0";
        } elseif ($atomic_type instanceof Atomic\TString) {
            $replacement = $display_expr . " === '' || " . $display_expr . " === '0'";
        } elseif ($atomic_type instanceof Atomic\TArray
            || $atomic_type instanceof Atomic\TList
            || $atomic_type instanceof Atomic\TKeyedArray
        ) {
            $replacement = $display_expr . " === []";
        } elseif ($atomic_type instanceof Atomic\TBool) {
            $replacement = $display_expr . " === false";
        } else {
            if(!$atomic_type instanceof Atomic\TMixed) {
                var_dump(get_class($atomic_type));
                var_dump($type->getId());
            }
        }

        if($replacement !== null){
            $startPos = $expr->getStartFilePos();
            $endPos = $expr->getEndFilePos()+1;
            //TODO: possible improvement: detect !empty and invert conditions to avoid convoluted syntax like if(!(EXPR === 0))
            $file_manipulation = new FileManipulation($startPos, $endPos, '('.$replacement.')');
            $event->setFileReplacements([$file_manipulation]);
        }

        return true;
    }
}
