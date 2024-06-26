<?php

namespace JMS\JobQueueBundle\Controller;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Entity\Repository\JobManager;
use JMS\JobQueueBundle\View\JobFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Annotation\Route;

class JobController extends AbstractController
{
    private ContainerBagInterface $containerBag;
    private JobManager $jobManager;
    private EntityManagerInterface $entityManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        ContainerBagInterface $containerBag,
        JobManager $jobManager
    )
    {
        $this->entityManager = $entityManager;
        $this->containerBag = $containerBag;
        $this->jobManager = $jobManager;
    }

    /**
     * @Route("/", name = "jms_jobs_overview")
     */
    public function overviewAction(Request $request): Response
    {
        $jobFilter = JobFilter::fromRequest($request);

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('j')->from('JMSJobQueueBundle:Job', 'j')
            ->where($qb->expr()->isNull('j.originalJob'))
            ->orderBy('j.id', 'desc');

        $lastJobsWithError = $jobFilter->isDefaultPage() ? $this->jobManager->findLastJobsWithError(5) : [];
        foreach ($lastJobsWithError as $i => $job) {
            $qb->andWhere($qb->expr()->neq('j.id', '?'.$i));
            $qb->setParameter($i, $job->getId());
        }

        if ( ! empty($jobFilter->command)) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('j.command', ':commandQuery'),
            // Doesn't work for postgres
            //$qb->expr()->like('j.args', ':commandQuery')
            ))
                ->setParameter('commandQuery', '%'.$jobFilter->command.'%');
        }

        if ( ! empty($jobFilter->state)) {
            $qb->andWhere($qb->expr()->eq('j.state', ':jobState'))
                ->setParameter('jobState', $jobFilter->state);
        }

        $perPage = 50;

        $query = $qb->getQuery();
        $query->setMaxResults($perPage + 1);
        $query->setFirstResult(($jobFilter->page - 1) * $perPage);

        $jobs = $query->getResult();

        return $this->render('@JMSJobQueue/Job/overview.html.twig', array(
            'jobsWithError' => $lastJobsWithError,
            'jobs' => array_slice($jobs, 0, $perPage),
            'jobFilter' => $jobFilter,
            'hasMore' => count($jobs) > $perPage,
            'jobStates' => Job::getStates(),
        ));
    }

    /**
     * @Route("/{id}", name = "jms_jobs_details")
     * @throws Exception
     */
    public function detailsAction(Job $job): Response
    {
        $relatedEntities = array();
        foreach ($job->getRelatedEntities() as $entity) {
            $class = ClassUtils::getClass($entity);
            $relatedEntities[] = array(
                'class' => $class,
                'id' => json_encode($this->entityManager->getClassMetadata($class)->getIdentifierValues($entity)),
                'raw' => $entity,
            );
        }

        $statisticData = $statisticOptions = array();
        if ($this->containerBag->get('jms_job_queue.statistics')) {
            $dataPerCharacteristic = array();
            foreach ($this->entityManager->getConnection()->executeQuery("SELECT * FROM jms_job_statistics WHERE job_id = ".$job->getId()) as $row) {
                $dataPerCharacteristic[$row['characteristic']][] = array(
                    // hack because postgresql lower-cases all column names.
                    array_key_exists('createdAt', $row) ? $row['createdAt'] : $row['createdat'],
                    array_key_exists('charValue', $row) ? $row['charValue'] : $row['charvalue'],
                );
            }

            if ($dataPerCharacteristic) {
                $statisticData = array(array_merge(array('Time'), $chars = array_keys($dataPerCharacteristic)));
                $startTime = strtotime($dataPerCharacteristic[$chars[0]][0][0]);
                $endTime = strtotime($dataPerCharacteristic[$chars[0]][count($dataPerCharacteristic[$chars[0]])-1][0]);
                $scaleFactor = $endTime - $startTime > 300 ? 1/60 : 1;

                // This assumes that we have the same number of rows for each characteristic.
                for ($i=0,$c=count(reset($dataPerCharacteristic)); $i<$c; $i++) {
                    $row = array((strtotime($dataPerCharacteristic[$chars[0]][$i][0]) - $startTime) * $scaleFactor);
                    foreach ($chars as $name) {
                        $value = (float) $dataPerCharacteristic[$name][$i][1];

                        switch ($name) {
                            case 'memory':
                                $value /= 1024 * 1024;
                                break;
                        }

                        $row[] = $value;
                    }

                    $statisticData[] = $row;
                }
            }
        }

        return $this->render('@JMSJobQueue/Job/details.html.twig', array(
            'job' => $job,
            'relatedEntities' => $relatedEntities,
            'incomingDependencies' => $this->jobManager->getIncomingDependencies($job),
            'statisticData' => $statisticData,
            'statisticOptions' => $statisticOptions,
        ));
    }

    /**
     * @Route("/{id}/retry", name = "jms_jobs_retry_job")
     */
    public function retryJobAction(Job $job): RedirectResponse
    {
        $state = $job->getState();

        if (
            Job::STATE_FAILED !== $state &&
            Job::STATE_TERMINATED !== $state &&
            Job::STATE_INCOMPLETE !== $state
        ) {
            throw new HttpException(400, 'Given job can\'t be retried');
        }

        $retryJob = clone $job;

        $this->entityManager->persist($retryJob);
        $this->entityManager->flush();

        $url = $this->generateUrl('jms_jobs_details', array('id' => $retryJob->getId()));

        return new RedirectResponse($url, 201);
    }
}
