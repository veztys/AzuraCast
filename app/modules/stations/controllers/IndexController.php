<?php
use \Entity\Station;
use \Entity\StationManager;

use \Entity\Song;
use \Entity\SongHistory;
use \Entity\SongVote;

class Stations_IndexController extends \PVL\Controller\Action\Station
{
    public function selectAction()
    {}

	public function indexAction()
    {
        /**
         * Statistics
         */

        $threshold = strtotime('-1 month');

        // Statistics by day.
        $daily_stats = $this->em->createQuery('SELECT a FROM Entity\Analytics a WHERE a.station_id = :station_id AND a.type = :type ORDER BY a.timestamp ASC')
            ->setParameter('station_id', $this->station->id)
            ->setParameter('type', 'day')
            ->getArrayResult();

        $daily_ranges = array();
        $daily_averages = array();
        $days_of_week = array();

        foreach($daily_stats as $stat)
        {
            $daily_ranges[] = array($stat['timestamp']*1000, $stat['number_min'], $stat['number_max']);
            $daily_averages[] = array($stat['timestamp']*1000, $stat['number_avg']);

            $day_of_week = date('l', $stat['timestamp']+(86400/2));
            $days_of_week[$day_of_week][] = $stat['number_avg'];
        }

        $day_of_week_stats = array();
        foreach($days_of_week as $day_name => $day_totals)
            $day_of_week_stats[] = array($day_name, round(array_sum($day_totals) / count($day_totals), 2));

        $this->view->day_of_week_stats = json_encode($day_of_week_stats);

        $this->view->daily_ranges = json_encode($daily_ranges);
        $this->view->daily_averages = json_encode($daily_averages);

        // Statistics by hour.
        $hourly_stats = $this->em->createQuery('SELECT a FROM Entity\Analytics a WHERE a.station_id = :station_id AND a.type = :type AND a.timestamp >= :timestamp ORDER BY a.timestamp ASC')
            ->setParameter('station_id', $this->station->id)
            ->setParameter('type', 'hour')
            ->setParameter('timestamp', $threshold)
            ->getArrayResult();

        $hourly_averages = array();
        $hourly_ranges = array();
        $totals_by_hour = array();

        foreach($hourly_stats as $stat)
        {
            $hourly_ranges[] = array($stat['timestamp']*1000, $stat['number_min'], $stat['number_max']);
            $hourly_averages[] = array($stat['timestamp']*1000, $stat['number_avg']);

            $hour = date('G', $stat['timestamp']);
            $totals_by_hour[$hour][] = $stat['number_avg'];
        }

        $this->view->hourly_ranges = json_encode($hourly_ranges);
        $this->view->hourly_averages = json_encode($hourly_averages);

        $averages_by_hour = array();
        for($i = 0; $i < 24; $i++)
        {
            $totals = $totals_by_hour[$i];
            $averages_by_hour[] = array($i.':00', round(array_sum($totals) / count($totals), 2));
        }

        $this->view->averages_by_hour = json_encode($averages_by_hour);

        /**
         * Play Count Statistics
         */

        // Song statistics.
        $song_totals = array();
        
        // Most played songs.
        $song_totals_raw = array();
        $song_totals_raw['played'] = $this->em->createQuery('SELECT sh.song_id, COUNT(sh.id) AS records FROM Entity\SongHistory sh WHERE sh.station_id = :station_id AND sh.timestamp >= :timestamp GROUP BY sh.song_id ORDER BY records DESC')
            ->setParameter('station_id', $this->station->id)
            ->setParameter('timestamp', $threshold)
            ->setMaxResults(40)
            ->getArrayResult();

        $ignored_songs = $this->_getIgnoredSongs();
        $song_totals_raw['played'] = array_filter($song_totals_raw['played'], function($value) use ($ignored_songs)
        {
            return !(isset($ignored_songs[$value['song_id']]));
        });

        // Compile the above data.
        $song_totals = array();
        foreach($song_totals_raw as $total_type => $total_records)
        {
            foreach($total_records as $total_record)
            {
                $song = \Entity\Song::find($total_record['song_id']);
                $total_record['song'] = $song;

                $song_totals[$total_type][] = $total_record;
            }

            $song_totals[$total_type] = array_slice($song_totals[$total_type], 0, 10, TRUE);
        }

        $this->view->song_totals = $song_totals;

        /**
         * Song "Deltas" (Changes in Listener Count)
         */

        $songs_played_raw = $this->_getEligibleHistory();
        $songs = array();

        foreach($songs_played_raw as $i => $song_row)
        {
            if (!isset($songs_played_raw[$i+1]))
                break;

            $song_row['stat_start'] = $song_row['listeners'];

            if ($i+1 == count($songs_played_raw))
                $song_row['stat_end'] = $song_row['stat_start'];
            else
                $song_row['stat_end'] = $songs_played_raw[$i+1]['listeners'];

            $song_row['stat_delta'] = $song_row['stat_end'] - $song_row['stat_start'];

            $songs[] = $song_row;
        }

        usort($songs, function($a_arr, $b_arr) {
            $a = $a_arr['stat_delta'];
            $b = $b_arr['stat_delta'];

            if ($a == $b) return 0;
            return ($a > $b) ? 1 : -1;
        });


        $this->view->best_performing_songs = array_reverse(array_slice($songs, -5));
        $this->view->worst_performing_songs = array_slice($songs, 0, 5);
    }

    public function timelineAction()
    {
        $songs_played_raw = $this->_getEligibleHistory();

        // Get current events within threshold.
        $threshold = $songs_played_raw[0]['timestamp'];
        $events = \Entity\Schedule::getEventsInRange($this->station->id, $threshold, time());

        $songs = array();

        foreach($songs_played_raw as $i => $song_row)
        {
            if (!isset($songs_played_raw[$i+1]))
                break;

            $start_timestamp = $song_row['timestamp'];
            $song_row['stat_start'] = $song_row['listeners'];

            if ($i+1 == count($songs_played_raw))
            {
                $end_timestamp = $start_timestamp;
                $song_row['stat_end'] = $song_row['stat_start'];
            }
            else
            {
                $end_timestamp = $songs_played_raw[$i+1]['timestamp'];
                $song_row['stat_end'] = $songs_played_raw[$i+1]['listeners'];
            }

            $song_row['stat_delta'] = $song_row['stat_end'] - $song_row['stat_start'];

            foreach($events as $event)
            {
                if ($event['end_time'] >= $start_timestamp && $event['start_time'] <= $end_timestamp)
                {
                    $song_row['event'] = $event;
                    break;
                }
            }

            $songs[] = $song_row;
        }

        $format = $this->_getParam('format', 'html');
        if ($format == 'csv')
        {
            $this->doNotRender();

            $export_all = array();
            $export_all[] = array('Date', 'Time', 'Listeners', 'Delta', 'Likes', 'Dislikes', 'Track', 'Artist', 'Event');

            foreach($songs as $song_row)
            {
                $export_row = array(
                    date('Y-m-d', $song_row['timestamp']),
                    date('g:ia', $song_row['timestamp']),
                    $song_row['stat_start'],
                    $song_row['stat_delta'],
                    $song_row['score_likes'],
                    $song_row['score_dislikes'],
                    ($song_row['song']['title']) ? $song_row['song']['title'] : $song_row['song']['text'],
                    $song_row['song']['artist'],
                    ($song_row['event']) ? $song_row['event']['title'] : '',
                );

                $export_all[] = $export_row;
            }

            \DF\Export::csv($export_all);
            return;
        }
        else
        {
            $songs = array_reverse($songs);
            $pager = new \DF\Paginator($songs, $this->_getParam('page', 1), 50);

            $this->view->pager = $pager;
        }
    }

    public function votesAction()
    {
        $threshold = strtotime('-2 weeks');

        $votes_raw = $this->em->createQuery('SELECT sv.song_id, SUM(sv.vote) AS vote_total FROM Entity\SongVote sv WHERE sv.station_id = :station_id AND sv.timestamp >= :threshold GROUP BY sv.song_id')
            ->setParameter('station_id', $this->station->id)
            ->setParameter('threshold', $threshold)
            ->getArrayResult();

        $ignored_songs = $this->_getIgnoredSongs();
        $votes_raw = array_filter($votes_raw, function($value) use ($ignored_songs)
        {
            return !(isset($ignored_songs[$value['song_id']]));
        });

        \PVL\Utilities::orderBy($votes_raw, 'vote_total DESC');

        $votes = array();
        foreach($votes_raw as $row)
        {
            $row['song'] = Song::find($row['song_id']);
            $votes[] = $row;
        }

        $this->view->votes = $votes;
    }

    public function addadminAction()
    {
        $this->doNotRender();

        $record = new StationManager;
        $record->email = $_REQUEST['email'];
        $record->station = $this->station;
        $record->save();

        $this->redirectFromHere(array('action' => 'index', 'id' => NULL, 'email' => NULL));
    }

    public function removeadminAction()
    {
        $this->doNotRender();
        
        $id_hash = $this->_getParam('id');

        $record = StationManager::getRepository()->findOneBy(array('station_id' => $this->station->id, 'id' => $id_hash));
        if ($record instanceof StationManager)
            $record->delete();

        $this->redirectFromHere(array('action' => 'index', 'id' => NULL));
    }

    /**
     * Utility Functions
     */

    protected function _getEligibleHistory()
    {
        $cache_name = 'station_center_history_'.$this->station->id;
        $songs_played_raw = \DF\Cache::get($cache_name);

        if (!$songs_played_raw)
        {
            try
            {
                $first_song = $this->em->createQuery('SELECT sh.timestamp FROM Entity\SongHistory sh WHERE sh.station_id = :station_id AND sh.listeners IS NOT NULL ORDER BY sh.timestamp ASC')
                    ->setParameter('station_id', $this->station->id)
                    ->setMaxResults(1)
                    ->getSingleScalarResult();
            }
            catch(\Exception $e)
            {
                $first_song = strtotime('Yesterday 00:00:00');
            }

            $min_threshold = strtotime('-2 weeks');
            $threshold = max($first_song, $min_threshold);

            // Get all songs played in timeline.
            $songs_played_raw = $this->em->createQuery('SELECT sh, s FROM Entity\SongHistory sh LEFT JOIN sh.song s WHERE sh.station_id = :station_id AND sh.timestamp >= :timestamp AND sh.listeners IS NOT NULL ORDER BY sh.timestamp ASC')
                ->setParameter('station_id', $this->station->id)
                ->setParameter('timestamp', $threshold)
                ->getArrayResult();

            $ignored_songs = $this->_getIgnoredSongs();
            $songs_played_raw = array_filter($songs_played_raw, function($value) use ($ignored_songs)
            {
                return !(isset($ignored_songs[$value['song_id']]));
            });

            $songs_played_raw = array_values($songs_played_raw);

            \DF\Cache::save($songs_played_raw, $cache_name, array(), 60*5);
        }

        return $songs_played_raw;
    }

    protected function _getIgnoredSongs()
    {
        $song_hashes = \DF\Cache::get('station_center_ignored_songs');

        if (!$song_hashes)
        {
            $ignored_phrases = array('Offline', 'Sweeper', 'Bumper', 'Unknown');

            $qb = $this->em->createQueryBuilder();
            $qb->select('s.id')->from('Entity\Song', 's');

            foreach($ignored_phrases as $i => $phrase)
            {
                $qb->orWhere('s.text LIKE ?'.($i+1));
                $qb->setParameter($i+1, '%'.$phrase.'%');
            }

            $song_hashes_raw = $qb->getQuery()->getArrayResult();
            $song_hashes = array();

            foreach($song_hashes_raw as $row)
                $song_hashes[$row['id']] = $row['id'];

            \DF\Cache::save($song_hashes, 'station_center_ignored_songs', array(), 86400);
        }

        return $song_hashes;
    }
}