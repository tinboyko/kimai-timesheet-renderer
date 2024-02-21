<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\CodalityBundle\Export\Timesheet;

use App\Activity\ActivityStatisticService;
use App\Entity\ExportableItem;
use App\Entity\MetaTableTypeInterface;
use App\Event\ActivityMetaDisplayEvent;
use App\Event\CustomerMetaDisplayEvent;
use App\Event\MetaDisplayEventInterface;
use App\Event\ProjectMetaDisplayEvent;
use App\Event\TimesheetMetaDisplayEvent;
use App\Event\UserPreferenceDisplayEvent;
use App\Export\Base\HtmlRenderer;
use App\Export\Base\HtmlRenderer as BaseHtmlRenderer;
use App\Export\Base\RendererTrait;
use App\Export\TimesheetExportInterface;
use App\Project\ProjectStatisticService;
use App\Repository\Query\CustomerQuery;
use App\Repository\Query\TimesheetQuery;
use App\Twig\SecurityPolicy\ExportPolicy;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Extension\SandboxExtension;

final class TimesheetIdRenderer extends BaseHtmlRenderer implements TimesheetExportInterface
{
    use RendererTrait;

    /**
     * @var string
     */
    private $id = 'html';
    /**
     * @var string
     */
    private $template = 'default.html.twig';

    public function __construct(
        protected Environment $twig,
        protected EventDispatcherInterface $dispatcher,
        private ProjectStatisticService $projectStatisticService,
        private ActivityStatisticService $activityStatisticService
    ) {
    }

    /**
     * @param MetaDisplayEventInterface $event
     * @return MetaTableTypeInterface[]
     */
    protected function findMetaColumns(MetaDisplayEventInterface $event): array
    {
        $this->dispatcher->dispatch($event);

        return $event->getFields();
    }

    protected function getOptions(TimesheetQuery $query): array
    {
        $decimal = false;
        if (null !== $query->getCurrentUser()) {
            $decimal = $query->getCurrentUser()->isExportDecimal();
        } elseif (null !== $query->getUser()) {
            $decimal = $query->getUser()->isExportDecimal();
        }

        return ['decimal' => $decimal];
    }

    /**
     * @param ExportableItem[] $timesheets
     * @param TimesheetQuery $query
     * @return Response
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function render(array $timesheets, TimesheetQuery $query): Response
    {
        $groupedTimesheets = [];
        $descriptions = [];
        $beginDates = [];
        foreach ($timesheets as $timesheet_index => $timesheet) {
            $description = $timesheet->getDescription();
            if ($description) {
                $descriptionIndex = array_search($description, $descriptions);
                $beginDateIndex = array_search($timesheet->getBegin(), $beginDates);
                if ($descriptionIndex !== false) {
                    $existingTimesheet = $groupedTimesheets[$descriptionIndex];
                    if ($timesheet->getBegin()->format('Y-m-d') == $beginDates[$descriptionIndex]) {
                        $existingTimesheet->duration += $timesheet->getDuration();
                        continue;
                    }
                }
            }

            $groupedTimesheets[] = (object)[
                'id' => $timesheet->getId(),
                'begin' => $timesheet->getBegin(),
                'duration' => $timesheet->getDuration(),
                'description' => $timesheet->getDescription(),
                'user' => $timesheet->getUser(),
                'activity' => $timesheet->getActivity(),
                'project' => $timesheet->getProject()
            ];
            $descriptions[$timesheet_index] = $timesheet->getDescription();
            $beginDates[$timesheet_index] = $timesheet->getBegin()->format('Y-m-d');
        }

        /** @var CustomerQuery $customerQuery */
        $customerQuery = $query->copyTo(new CustomerQuery());

        $timesheetMetaFields = $this->findMetaColumns(new TimesheetMetaDisplayEvent($query, TimesheetMetaDisplayEvent::EXPORT));
        $customerMetaFields = $this->findMetaColumns(new CustomerMetaDisplayEvent($customerQuery, CustomerMetaDisplayEvent::EXPORT));
        $projectMetaFields = $this->findMetaColumns(new ProjectMetaDisplayEvent($query, ProjectMetaDisplayEvent::EXPORT));
        $activityMetaFields = $this->findMetaColumns(new ActivityMetaDisplayEvent($query, ActivityMetaDisplayEvent::EXPORT));

        $event = new UserPreferenceDisplayEvent(UserPreferenceDisplayEvent::EXPORT);
        $this->dispatcher->dispatch($event);
        $userPreferences = $event->getPreferences();

        $summary = $this->calculateSummary($timesheets);

        // enable basic security measures
        $sandbox = new SandboxExtension(new ExportPolicy());
        $sandbox->enableSandbox();
        $this->twig->addExtension($sandbox);

        $content = $this->twig->render($this->getTemplate(), array_merge([
            'entries' => $groupedTimesheets,
            'query' => $query,
            'summaries' => $summary,
            'budgets' => $this->calculateProjectBudget($timesheets, $query, $this->projectStatisticService),
            'activity_budgets' => $this->calculateActivityBudget($timesheets, $query, $this->activityStatisticService),
            'timesheetMetaFields' => $timesheetMetaFields,
            'customerMetaFields' => $customerMetaFields,
            'projectMetaFields' => $projectMetaFields,
            'activityMetaFields' => $activityMetaFields,
            'userPreferences' => $userPreferences,
        ], $this->getOptions($query)));

        $response = new Response();
        $response->setContent($content);

        return $response;
    }

    public function setTemplate(string $filename): HtmlRenderer
    {
        $this->template = $filename;

        return $this;
    }

    public function setId(string $id): HtmlRenderer
    {
        $this->id = $id;

        return $this;
    }

    protected function getTemplate(): string
    {
        return '@Codality/export.ggsa.twig';
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return 'ggsa';
    }

    public function getName(): string
    {
        return 'GGSA';
    }
}
