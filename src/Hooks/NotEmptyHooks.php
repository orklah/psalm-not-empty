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

        if ($expr->expr instanceof Variable) {
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
            // TODO: add functionnality with isSingleAndMaybeNullable and add || EXPR !== null
            return true;
        }

        $startPos = $expr->getStartFilePos() + 1;
        $endPos = $expr->getEndFilePos();

        $atomic_types = $type->getAtomicTypes();
        $atomic_type = array_shift($atomic_types);
        $display_expr = (string)$expr->expr;
        if ($atomic_type instanceof Atomic\TInt) {
            $file_manipulation = new FileManipulation($startPos, $endPos, $display_expr . " === 0");
            $event->setFileReplacements([$file_manipulation]);
        } elseif ($atomic_type instanceof Atomic\TFloat) {
            $file_manipulation = new FileManipulation($startPos, $endPos, $display_expr . " === 0.0");
            $event->setFileReplacements([$file_manipulation]);
        } elseif ($atomic_type instanceof Atomic\TString) {
            $file_manipulation = new FileManipulation($startPos, $endPos, "(" . $display_expr . " === '' || " . $display_expr . " === '0')");
            $event->setFileReplacements([$file_manipulation]);
        } elseif ($atomic_type instanceof Atomic\TArray) {
            $file_manipulation = new FileManipulation($startPos, $endPos, $display_expr . " === []");
            $event->setFileReplacements([$file_manipulation]);
        } elseif ($atomic_type instanceof Atomic\TBool) {
            $file_manipulation = new FileManipulation($startPos, $endPos, $display_expr . " === false");
            $event->setFileReplacements([$file_manipulation]);
        } else {
            var_dump(get_class($atomic_type));
        }

        return true;
    }
}
