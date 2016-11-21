<?php

namespace SchulzeFelix\Stat\Api;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use SchulzeFelix\Stat\Exceptions\ApiException;

class StatBulk extends BaseStat
{

    public function list()
    {
        $response = $this->performQuery('bulk/list');

        $bulkJobs = collect();

        if($response['resultsreturned'] == 0) {
            return $bulkJobs;
        }

        if($response['resultsreturned'] == 1) {
            $bulkJobs->push($response['Result']);
        }

        if($response['resultsreturned'] > 1) {
            $bulkJobs = collect($response['Result']);
        }

        return $bulkJobs->transform(function ($job, $key) {

            return [
                'id' => (int)$job['Id'],
                'job_type' => $job['JobType'],
                'format' => $job['Format'],
                'date' => Carbon::parse($job['Date']),
                'status' => $job['Status'],
                'url' => $job['Url'],
                'stream_url' => $job['StreamUrl'],
                'created_at' => Carbon::parse($job['CreatedAt']),
            ];

        });
    }

    /**
     * @param Carbon $date
     * @param array|null $sites
     * @param string $rankType
     * @param array|null $engines
     * @param bool $currentlyTrackedOnly
     * @param bool $crawledKeywordsOnly
     * @return int
     */
    public function ranks(Carbon $date, array $sites = null, $rankType = 'highest', $engines = ['google', 'yahoo', 'bing'], bool $currentlyTrackedOnly = false , bool $crawledKeywordsOnly = false)
    {
        $this->validateBulkDate($date);

        $arguments['date'] = $date->toDateString();
        $arguments['rank_type'] = $rankType;
        $arguments['currently_tracked_only'] = $currentlyTrackedOnly;
        $arguments['crawled_keywords_only'] = $crawledKeywordsOnly;

        if( ! is_null($sites) && count($sites) > 0){
            $arguments['site_id'] = implode(',', $sites);;
        }
        if( ! is_null($engines) && count($engines) > 0){
            $arguments['engines'] = implode(',', $engines);
        }
        $response = $this->performQuery('bulk/ranks', $arguments);

        return (int) $response['Result']['Id'];
    }

    public function status($bulkJobID)
    {
        $response = $this->performQuery('bulk/status', ['id' => $bulkJobID]);

        $jobStatus = [];
        $jobStatus['id'] = (int)$response['Result']['Id'];
        $jobStatus['job_type'] = $response['Result']['JobType'];
        $jobStatus['format'] = $response['Result']['Format'];
        $jobStatus['date'] = Carbon::parse($response['Result']['Date']);

        $jobStatus['sites'] = collect();
        if(isset($response['Result']['SiteId'])){
            $jobStatus['sites'] = collect( explode(',', $response['Result']['SiteId']))
                                ->transform(function ($site, $key) {
                                    return (int)$site;
                                });
        }

        //Current Job Status (NotStarted,InProgress,Completed,Deleted,Failed)
        $jobStatus['status'] = $response['Result']['Status'];
        $jobStatus['url'] = $response['Result']['Url'] ?? null;
        $jobStatus['stream_url'] = $response['Result']['StreamUrl'] ?? null;
        $jobStatus['created_at'] = Carbon::parse($response['Result']['CreatedAt']);

        return $jobStatus;

    }

    public function delete($bulkJobID)
    {
        $response = $this->performQuery('bulk/delete', ['id' => $bulkJobID]);

        return (int) $response['Result']['Id'];
    }

    public function siteRankingDistributions($date)
    {
        $this->validateBulkDate($date);

        $response = $this->performQuery('bulk/site_ranking_distributions', ['date' => $date->toDateString()]);
        return (int) $response['Result']['Id'];
    }

    public function tagRankingDistributions($date)
    {
        $this->validateBulkDate($date);

        $response = $this->performQuery('bulk/tag_ranking_distributions', ['date' => $date->toDateString()]);
        return (int) $response['Result']['Id'];
    }

    public function get($bulkJobID)
    {
//        $bulkStatus = Cache::remember('bulkstatus' . $bulkJobID, 1200, function () use($bulkJobID) {
//            return $this->status($bulkJobID);
//        });

            $bulkStatus = $this->status($bulkJobID);

        if($bulkStatus['status'] != 'Completed') {
            throw ApiException::resultError('Bulk Job is not completed. Current status: ' . $bulkJobID['status'] . '.');
        }

        $bulkStream = $this->statClient->downloadBulkJobStream($bulkStatus['stream_url']);

        return $this->parseBulkJob($bulkStream['Response']);
    }

    /**
     * @param Carbon $date
     * @throws ApiException
     */
    private function validateBulkDate(Carbon $date)
    {
        if ($date->isSameDay(Carbon::now()) or $date->isFuture()) {
            throw ApiException::resultError('You cannot create bulk jobs for the current date or dates in the future.');
        }
    }


    private function parseBulkJob($bulkStream)
    {
        $projects = collect();

        if(isset($bulkStream['Project']['Id'])){
            $projects->push($bulkStream['Project']);
        } else {
            $projects = collect($bulkStream['Project']);
        }

        $projects->transform(function ($project, $key) {
            return $this->transformProject($project);
        });

        return $projects;
    }

    private function transformProject($project)
    {
        $transformedProject['id'] = (int)$project['Id'];
        $transformedProject['name'] = $project['Name'];
        $transformedProject['total_sites'] = (int)$project['TotalSites'];
        $transformedProject['created_at'] = Carbon::parse($project['CreatedAt']);

        //Todo Test!
        if( $project['TotalSites'] == 0) {}
        if( $project['TotalSites'] == 1) {
            $transformedProject['sites'] = collect([$project['Site']]);
        }
        if( $project['TotalSites'] > 1) {
            $transformedProject['sites'] = collect($project['Site']);
        }

        $transformedProject['sites']->transform(function ($site, $key) {
            return $this->transformSite($site);
        });

        return $transformedProject;
    }


    private function transformSite($site)
    {
        $transformedSite['id'] = (int)$site['Id'];
        $transformedSite['url'] = $site['Url'];
        $transformedSite['total_keywords'] = (int)$site['TotalKeywords'];
        $transformedSite['created_at'] = Carbon::parse($site['CreatedAt']);

        if( $site['TotalKeywords'] == 0) {
            $transformedSite['keywords'] = collect();
        }

        if( $site['TotalKeywords'] == 1) {
            $transformedSite['keywords'] = collect([$site['Keyword']]);
        }

        if( $site['TotalKeywords'] > 1) {
            $transformedSite['keywords'] = collect($site['Keyword']);
        }

        $transformedSite['keywords']->transform(function ($keyword, $key) {
            return $this->transformKeyword($keyword);
        });

        return $transformedSite;


    }

    private function transformKeyword($keyword)
    {
        $modifiedKeyword['id'] = (int)$keyword['Id'];
        $modifiedKeyword['keyword'] = $keyword['Keyword'];
        $modifiedKeyword['keyword_market'] = $keyword['KeywordMarket'];
        $modifiedKeyword['keyword_location'] = $keyword['KeywordLocation'];
        $modifiedKeyword['keyword_device'] = $keyword['KeywordDevice'];
        $modifiedKeyword['keyword_categories'] = $keyword['KeywordCategories'];

        if($keyword['KeywordTags'] == null) {
            $modifiedKeyword['keyword_tags'] = collect();
        } else {
            $modifiedKeyword['keyword_tags'] = collect(explode(',', $keyword['KeywordTags']));
        }

        if( is_null($keyword['KeywordStats']) ) {
            $modifiedKeyword['keyword_stats'] = null;
        } else {
            $modifiedKeyword['keyword_stats']['advertiser_competition'] = (float)$keyword['KeywordStats']['AdvertiserCompetition'];
            $modifiedKeyword['keyword_stats']['global_search_volume'] = (int)$keyword['KeywordStats']['GlobalSearchVolume'];
            $modifiedKeyword['keyword_stats']['targeted_search_volume'] = (int)$keyword['KeywordStats']['TargetedSearchVolume'];

            foreach ($keyword['KeywordStats']['LocalSearchTrendsByMonth'] as $month => $searchVolume) {
                if($searchVolume == '-') {
                    $searchVolume = '';
                } else {
                    $searchVolume = (int)$searchVolume;
                }
                $modifiedKeyword['keyword_stats']['local_search_trends_by_month'][strtolower($month)] = $searchVolume;
            }

            $modifiedKeyword['keyword_stats']['cpc'] = $keyword['KeywordStats']['CPC'];
        }

        $modifiedKeyword['created_at'] = Carbon::parse($keyword['CreatedAt']);
        $modifiedKeyword['ranking']['date'] = Carbon::parse($keyword['Ranking']['date']);
        $modifiedKeyword['ranking']['type'] = $keyword['Ranking']['type'];

        if(isset($keyword['Ranking']['Google'])){
            $modifiedKeyword['ranking']['google']['rank'] = $keyword['Ranking']['Google']['Rank'];
            $modifiedKeyword['ranking']['google']['base_rank'] = $keyword['Ranking']['Google']['BaseRank'];
            $modifiedKeyword['ranking']['google']['url'] = $keyword['Ranking']['Google']['Url'];
        }

        if(isset($keyword['Ranking']['Yahoo'])){
            $modifiedKeyword['ranking']['yahoo']['rank'] = $keyword['Ranking']['Yahoo']['Rank'];
            $modifiedKeyword['ranking']['yahoo']['url'] = $keyword['Ranking']['Yahoo']['Url'];
        }

        if(isset($keyword['Ranking']['Bing'])){
            $modifiedKeyword['ranking']['bing']['rank'] = $keyword['Ranking']['Bing']['Rank'];
            $modifiedKeyword['ranking']['bing']['url'] = $keyword['Ranking']['Bing']['Url'];
        }


        return $modifiedKeyword;
    }

}
