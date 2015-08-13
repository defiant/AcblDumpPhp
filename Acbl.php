<?php

class Acbl
{

    # ACBLscore constants
    const MAX_EVENTS = 50;
    const MAX_SECTIONS = 100;

    # Structure parameters
    const STRAT_STRUCTURE_SIZE = 95;
    const SECTION_SUMMARY_BASE = 0x13e;
    const SECTION_SUMMARY_SIZE = 22;
    const PLAYER_STRUCTURE_SIZE = 120;
    const TEAM_MATCH_ENTRY_SIZE = 32;

    const PIGMENTATION_TYPES = 'BSRGP';

    public $data;
    public $event;
    public $gm;

    public $opt = [];

    public function __construct($file)
    {
        $fh = fopen($file, 'rb');
        $this->data = fread($fh, 32000);

        // Check for the 'AC3' magic bytes that appear at the start of all ACBLscore
        // game files.
        if (substr($this->data, 0, 6) != "\x12\x0a\x03AC3") {
            throw new Exception('This doesnt look like an ACBL gamefile');
        }
    }

    public function decode()
    {
        $ix = 0;
        for ($i = 0; $i < self::MAX_EVENTS; $i++) {
            $p = unpack('V', substr($this->data, 0x12 + 4 * $i, 4));

            if ($p[1]) {
                $eventTypeId = unpack('C', substr($this->data, 0xda + $i))[1];
                $eventScoringId = unpack('C', substr($this->data, 0x10c + $i))[1];

                $this->event = [
                    'event_id' => $i + 1,
                    'event_type_id' => $eventTypeId,
                    'event_type' => $this->getEventType($eventTypeId),
                    'event_scoring_id' => $eventScoringId,
                    'event_scoring' => $this->getScoring($eventScoringId)
                ];

                $rankStr = $this->eventDetails($p, $this->isTeams());
                $this->addSectionCombining();
                $this->addSections($rankStr);
                $this->gm['event'][$ix] = $this->event;
                $ix++;
            }
        }
        return $this->gm;
    }

    protected function isTeams()
    {
        return $this->event['event_type_id'] == 1 || $this->event['event_type_id'] == 4;
    }

    protected function getEventType($id)
    {
        $events = ['Pairs', 'Teams', 'Individual', 'Home Style Pairs', 'BAM', 'Series Winner'];
        return $events[$id];
    }

    protected function getScoring($id)
    {
        $scoring = ['Matchpoints', 'IMPs with computed datum', 'Average IMPs', 'Total IMPs', 'Instant Matchpoints', 'BAM Teams', 'Win/Loss', 'Victory Points', 'Knockout', null, 'Series Winner', null, null, null, null, null, 'BAM Matchpoints', null, 'Compact KO'];
        return $scoring[$id];
    }

    protected function clubGameType($offset)
    {
        $type = ['Open', 'Invitational', 'Novice', 'BridgePlus', 'Pupil', 'Introductory'];
        return $type[$offset];
    }

    protected function getMovementType($offset)
    {
        $movements = ['Mitchell', 'Howell', 'Web', 'External', 'External BAM', 'Barometer', 'Manual Mitchell', 'Manual Howell'];
        return $movements[$offset];
    }

    protected function getAcblPlayerRank($letter)
    {
        $ranks = [  ' ' => 'Rookie', 'A' => 'Junior Master', 'B' => 'Club Master',
            'C' => 'Sectional Master', 'D' => 'Regional Master', 'E' => 'NABC Master',
            'F' => 'Advanced NABC Master', 'G' => 'Life Master', 'H' => 'Bronze Life Master',
            'I' => 'Silver Life Master', 'J' => 'Gold Life Master', 'K' => 'Diamond Life Master',
            'L' => 'Emerald Life Master', 'M' => 'Platinum Life Master', 'N' => 'Grand Life Master'
        ];
        return $ranks[$letter];
    }

    protected function getSpecialScore($score)
    {
        $scores = ['900' => 'Late Play', '950' => 'Not Played', '2040' => 'Ave-', '2050' => 'Ave', '2060' => 'Ave+'];
        return $scores[$score];
    }

    protected function zstring($str, $len)
    {
        # Parse a string where first byte gives the length of the string.
        return substr($str, $len+1, unpack('C', substr($str, $len, 1))[1]);
    }

    protected function eventDetails($p, $isTeams)
    {
        $this->event['mp_rating'] = [
            'p-factor' => unpack('v', substr($this->data, $p[1] + 0x7d))[1] / 1000,
            't-factor' => unpack('v', substr($this->data, $p[1] + 0x83))[1] / 1000,
            's-factor' => unpack('v', substr($this->data, $p[1] + 0x24f))[1] / 100
        ];

        $clubSessionNumber = unpack('C', substr($this->data, $p[1] + 0x95))[1];
        # It is not clear the best way to figure out if results are from a tournament.
        # One way is to check whether there is a club number. Another way is to look
        # at the club session number which is what is done below.
        $isTourney = $clubSessionNumber == 0 ? true : false;

        $this->event['tournament_flag'] = $isTourney;
        if (!$isTourney) {
            $this->event['club_num'] = $this->zstring($this->data, $p[1] + 0xb0);
            $this->event['club_session_num'] = $clubSessionNumber;
            $this->event['club_game_type'] = $this->clubGameType(unpack('C', substr($this->data, $p[1] + 0xa1)));
        }

        $this->event['session_num'] = unpack('C', substr($this->data, $p[1] + 0x8c))[1];
        $this->event['nstrats'] = unpack('C', substr($this->data, $p[1] + 0x9e))[1];
        $this->event['nsessions'] = unpack('C', substr($this->data, $p[1] + 0x9f))[1];

        if ($isTeams) {
            $this->event['nbrackets'] = unpack('C', substr($this->data, $p[1] + 0xc2))[1];
            $this->event['bracket_num'] = unpack('C', substr($this->data, $p[1] + 0xc3))[1];
        }

        $this->event['side_game_flag'] = unpack('C', substr($this->data, $p[1] + 0x253))[1];
        $this->event['stratify_by_avg_flag'] = unpack('C', substr($this->data, $p[1] + 0x2c8))[1];
        $this->event['non_ACBL_flag'] = unpack('C', substr($this->data, $p[1] + 0x2ca))[1];

        $this->event['final_session_flag'] = (int)$this->event['session_num'] == $this->event['nsessions'];

        $clubOrTournament = $isTourney ? 'tournament' : 'club';
        $directorOrCity = $isTourney ? 'city' : 'director';

        $this->event['event_name'] = $this->zstring($this->data, $p[1] + 0x4);
        $this->event['session_name'] = $this->zstring($this->data, $p[1] + 0x1e);
        $this->event[$directorOrCity] = $this->zstring($this->data, $p[1] + 0x2c);
        $this->event['sanction'] = $this->zstring($this->data, $p[1] + 0x3d);
        $this->event['date'] = $this->zstring($this->data, $p[1] + 0x48);
        $this->event[$clubOrTournament] = $this->zstring($this->data, $p[1] + 0x5c);
        $this->event['event_code'] = $this->zstring($this->data, $p[1] + 0x76);
        $this->event['qual_event_code'] = $this->zstring($this->data, $p[1] + 0xc5);
        $this->event['hand_set'] = $this->zstring($this->data, $p[1] + 0x244);

        $rankstr = '';

        for($i = 0; $i < $this->event['nstrats']; $i++){
            $this->event['strat'][$i] = $this->strat($p[1] + 0xd4 + $i * self::STRAT_STRUCTURE_SIZE);
            $rankstr .= $this->event['strat'][$i]['letter'];
        }

        return $rankstr;
    }

    protected function getRibbonColor($offset)
    {
        $ribbonColors = ['', 'Blue', 'Red', 'Silver', null, null, null, null, null, 'Blue/Red'];
        return $ribbonColors[$offset];
    }

    protected function strat($p)
    {
        return $st = [
            'first_overall_award' => unpack('v', substr($this->data, $p + 0x10, 2))[1] / 100,
            'ribbon_color' => $this->getRibbonColor(unpack('C', substr($this->data, $p + 0x12, 1))[1]),
            'ribbon_depth' => unpack('C', substr($this->data, $p + 0x13, 1))[1],
            'mpt_factor' => unpack('V', substr($this->data, $p + 0x14, 4))[1] / 10000,
            'overall_award_depth' => unpack('v', substr($this->data, $p + 0x18, 2))[1],
            'table_basis' => unpack('C', substr($this->data, $p + 0x1a, 2))[1],
            'min_mp' => unpack('v', substr($this->data, $p + 0x1e, 2))[1],
            'max_mp' => unpack('v', substr($this->data, $p + 0x20, 2))[1],
            'letter' => substr($this->data, $p + 0x22, 1),
            'club_pct_open_rating' => unpack('C', substr($this->data, $p + 0x23, 1))[1],
            'pigmentation_breakdown' => [
                'overall' => $this->pigmentation($p[1] + 0x32),
                'session' => $this->pigmentation($p[1] + 0x41),
                'section' => $this->pigmentation($p[1] + 0x50)
            ]
        ];
    }

    protected function pigmentation($p)
    {
        $pgset = [];
        $pct = unpack('v3', substr($this->data, $p, 6));
        $mp  = unpack('v3', substr($this->data, $p + 6, 6));
        $tp  = unpack('C3', substr($this->data, $p + 12, 12));

        for ($i = 1; $i < 4; $i++) {
            if(isset($pct[$i]) && $pct[$i]) {
                break;
            }

            array_push($pgset, ['pct' => $pct[$i] / 100, 'mp' => $mp[$i] / 100, 'type' => substr(self::PIGMENTATION_TYPES, $tp[$i], 1)]);
        }

        return $pgset;
    }

    protected function addSectionCombining()
    {
        $combining_and_ranking = [];
        for ($i = 0; $i < self::MAX_SECTIONS; $i++) {
            $p = self::SECTION_SUMMARY_BASE + self::SECTION_SUMMARY_SIZE * $i;
            if(unpack('C', substr($this->data, $p, 1)) != $this->event>['event_id']){
                continue;
            }

            $prevScore = unpack('C', substr($this->data, $p + 0xf, 1))[1];
            if ($prevScore != 0){
                continue;
            }

            # Found first section in a group of combined sections.
            $combined = [];
            $pc = $p;
            while(true){
                $prevRank = unpack('C', substr($this->data, $pc + 0x11, 1))[1];
                if ($prevRank == 0) {
                    # Found first section in a group of sections ranked together (applies to
                    # section awards, i.e. N-S and E-W can be ranked across multiple sections).
                    $rankedTogether = [];
                    $pr = $pc;
                    while (true) {
                        array_push($rankedTogether, $this->zstring($this->data, $pr + 0x1));
                        $nextRank = unpack('C', substr($this->data, $pr + 0x12))[1];
                        if ($nextRank == 0) {
                            array_push($combined, $rankedTogether);
                            break;
                        }
                        $pr = self::SECTION_SUMMARY_BASE + self::SECTION_SUMMARY_SIZE * ($nextRank - 1);
                    }
                }
                $nextScore = unpack('C', substr($this->data, $pc + 0x10, 1))[1];
                if ($nextScore == 0){
                    break;
                }
                $pc = self::SECTION_SUMMARY_BASE + self::SECTION_SUMMARY_SIZE * ($nextScore-1);
            }
            array_push($combining_and_ranking, $combined);
        }

        $this->event['combining_and_ranking'] = isset($combining_and_ranking) ? $combining_and_ranking : [];
    }

    protected function addSections($rankStr)
    {
        $p  = self::SECTION_SUMMARY_BASE;
        for($i = 0; $i < self::MAX_SECTIONS; $i++) {
            if(unpack('C', substr($this->data, $p, 1)) != $this->event['event_id']){
                continue;
            }
            $sc = $this->section($p, $rankStr);
            $this->event['section'][$sc]['letter'] = $sc;
        }
    }

    protected function section($p, $rankStr)
    {
        $sc = ['letter' => $this->zstring($this->data, $p+1), 'rounds' => unpack('C', substr($this->data, $p + 0x14))[1]];

        # Board Results pointer
        $pBoardIdx = unpack('V', substr($this->data, $p + 8))[1];

        # Move on to Section Details Structure.
        $p = unpack('V', substr($this->data, $p + 4))[1];

        $pIndex = unpack('V4', substr($this->data, $p + 4));
        $isTeams = ($pIndex[1] == 0); //1 or 2
        $isIndy  = ($pIndex[3] != 0); //3 or 4

        $highestPairnum = unpack('v', substr($this->data, $p + 0x1b))[1];

        if($isTeams){
            $sc['movement_type'] = $this->getMovementType(unpack('C', substr($this->data, $p + 0x18))[1]);
            $sc['match_award'] = unpack('v', substr($this->data, $p + 0xb5))[1] / 100;
        }else{
            $sc['is_barometer'] = unpack('C', substr($this->data, $p + 0x47))[1];
            $sc['is_web'] = unpack('C', substr($this->data, $p + 0x60))[1];
            $sc['is_bam'] = unpack('C', substr($this->data, $p + 0xd5))[1];
            $sc['nboards'] = unpack('v', substr($this->data, $p + 0x19))[1];
            $sc['highest_pairnum'] = $highestPairnum;
            $sc['max_results_per_board'] = unpack('C', substr($this->data, $p + 0x61))[1];
            $sc['board_top'] = unpack('v', substr($this->data, $p + 0x1e))[1];
        }

        $sc['boards_per_round'] = unpack('C', substr($this->data, $p + 0x1d))[1];
        $sc['ntables'] = unpack('v', substr($this->data, $p + 0x48))[1];
        $sc['maximum_score'] = unpack('v', substr($this->data, $p + 0x4e))[1];

        if($this->opt['sectionsonly']) return $sc;

        $isHowell = unpack('C', substr($this->data, $p + 0x18))[1] == 1 ? 1 : 0;
        $phantom = unpack('c', substr($this->data, $p + 0x43))[1];
        ## print $sc->{'highest_pairnum'}, "\n";

        if($isHowell){
            $sc['is_howell'] = true;
            if(!$this->opt['noentries']){
                $pPNM = $p + 0xdd;
                $reassign = unpack('C80', substr($this->data, $p + 0xdd, 80))[1];
                for ($i = 1; $i <= $highestPairnum; $i++) {
                    $pairnum = $i;
                    if ($pairnum == $phantom) continue;
                    $str = substr($this->data, $pPNM + 0x50 + 2 * ($pairnum-1), 2);
                    list($table, $dir) = unpack('C2', $str);
                    $pEntry = unpack('V', substr($this->data, $pIndex[$dir-1] + 0x10 + 8 * $table, 4))[1];

                    # Rare pair number reassignments with ACBLscore EDMOV command.
                    if ($reassign[$i-1]) {
                        $pairnum = $reassign[$i - 1];
                    }
                    $sc['entry'][$pairnum] = $this->entry($pEntry, $rankStr);
                }
            }

            if (!$this->opt['noboards']) {
                $sc['board'] = $this->boards($pBoardIdx, $p, $isIndy);
            }
        }elseif(!$isTeams){
            # Mitchell movement
            $sc['is_howell'] = false;

            if(!$this->opt['noentries']) {
                $pPNM = $p + 0xdd;
                $ndir = $isIndy ? 4 : 2;
                $dirletter = ['N', 'E', 'S', 'W'];
                $reassign = unpack('C160', substr($this->data, $p + 0xdd, 160));

                for ($i = 1; $i <= $highestPairnum; $i++) {
                    for ($j = 0; $j < $ndir; $j++) {
                        $pairnum = $i;
                        if ($pairnum == $phantom && $j == 0 || $pairnum == -$phantom && $j == 1) continue;
                        $table = unpack('C', substr($this->data, $pPNM + 0xa0 + 4*($pairnum-1) + $j, 1))[1];
                        $pEntry = unpack('V', substr($this->data, $pIndex[$j] + 0x10 + 8 * $table, 4))[1];

                        # Rare pair number reassignments with ACBLscore EDMOV command.
                        if($reassign[4*($i-1)+$j]){
                            $pairnum = $reassign[4*($i-1)+$j];
                        }
                        $sc['entry'][$pairnum . $dirletter[$j]] = $this->entry($pEntry, $rankStr);
                    }
                }
            }
            if(!$this->opt['noboards']){
                $sc['board'] = $this->boards($pBoardIdx, $p, $isIndy);
            }
        }elseif($isTeams) {
            if (!$this->opt['noentries']) {
                $nteams = unpack('C', substr($this->data, $pIndex[0] + 6, 1))[1];
                $sc['nteams'] = $nteams;
                $pTeam = $pIndex[0] + 0x14;
                for ($i=0; $i < $nteams; $i++) {
                    $teamNum  = unpack('v', substr($this->data, $pTeam, 2))[1];
                    $nPlayers = unpack('v', substr($this->data, $pTeam + 2, 2))[1];
                    $pEntry   = unpack('V', substr($this->data, $pTeam + 4, 4))[1];
                    $sc['entry'][$teamNum] = $this->entry($pEntry, $rankStr, $nPlayers);
                    $pTeam += 8;
                }
            }
            $pTeamMatch = unpack('V', substr($this->data, $p + 0x23d))[1];
            $sc['matches'] = $this->teamMatches($this->data, $pTeamMatch, $pIndex[0])[1];
        }

        return $sc;
    }

    protected function entry($p, $rankStr, $nPlayers = 0)
    {

        $entry = [];
        $intFloats = unpack('l<6', substr($this->data, $p + 0x4, 24));

        // TODO: CHANGES INDEXES
        $entry['score_adjustment'] = $intFloats[0] / 100;
        $entry['score_unscaled']   = $intFloats[1] / 100;
        $entry['score_session']    = $intFloats[2] != -1 ? $intFloats[2] / 100 : null;
        $entry['score_carrover']   = $intFloats[3] / 100;
        $entry['score_final']      = $intFloats[4] != -1 ? $intFloats[4] / 100 : null;
        $entry['score_handicap']   = $intFloats[5] / 100;

        $entry['pct'] =  unpack('v', substr($this->data, $p + 0x1c, 2))[1] / 100;
        $entry['strat_num'] =  unpack('C', substr($this->data, $p + 0x1e, 1))[1];
        $entry['mp_average'] =  unpack('v', substr($this->data, $p + 0x20, 2))[1];
        $entry['nboards'] =  unpack('C', substr($this->data, $p + 0x2f, 1))[1];
        $entry['eligibility'] =  unpack('C', substr($this->data, $p + 0x33, 1))[1];

        $award = $this->award($p + 0x34, $rankStr);
        if($award) {
            $entry['award'] = $award;
        }
        $entry['rank'] = $this->rank($p + 0x5e);

        # There are three ways to determine the maximum number of players in an entry
        # structure. The method here is based on the size of the player structure and will
        # return 1, 2, or 6. The second and probably more proper method is to use the number
        # from the entry index table. The third is to infer it from the event type.
        if (!$nPlayers) {
            $nPlayers = (unpack('v', substr($this->data, $p + 0, 2))[1] - 0xa2) / self::PLAYER_STRUCTURE_SIZE;
        }

        for ($i = 0; $i < $nPlayers; $i++) {
            $entry['player'][$i] = $this->player($p + 0xa4 + $i * self::PLAYER_STRUCTURE_SIZE, $rankStr);
        }

        return $entry;
    }

    protected function player($p, $rankStr)
    {
        $pl['team_wins'] = unpack('v', substr($this->data, $p + 0x44, 2))[1] / 100;
        $pl['mp_total']  = unpack('v', substr($this->data, $p + 0x71, 2))[1];
        $pl['acbl_rank_letter'] = substr($this->data, $p + 0x73, 1)[1];
        $pl['acbl_rank'] = $this->getAcblPlayerRank($pl['acbl_rank_letter']);

        $pl['lname'] = $this->zstring($this->data, $p + 0);
        $pl['fname'] = $this->zstring($this->data, $p + 0x11);
        $pl['city'] = $this->zstring($this->data, $p + 0x22);
        $pl['state'] = $this->zstring($this->data, $p + 0x33);
        $pl['pnum'] = $this->zstring($this->data, $p + 0x36);
        $pl['db_key'] = $this->zstring($this->data, $p + 0x3e);
        $pl['country'] = $this->zstring($this->data, $p + 0x75);

        $award = $this->award($p + 0x48, $rankStr);
        if($award) {
            $pl['award'] = $award;
        }

        return $pl;
    }

    protected function award($p, $rankStr)
    {
        $awardSets = ['previous', 'current', 'total'];

        for ($i=0; $i<3; $i++) {
            $v = unpack('(vCC)3', substr($this->data, $p + $i * 12, 12) );
            for ($j=0; $j<=6; $j+=3) {
                if (!$v[$j]) break;
                $anyAward = 1;
                $reason = $v[$j+2] ? ($v[$j+2] >= 10 ? 'S' : 'O') . substr($rankStr, $v[$j+2] % 10 - 1, 1) : '';
                $aw[] = [$v[$j] / 100, substr(self::PIGMENTATION_TYPES, $v[$j+1], 1), $reason, $v[$j+2]];
            }
            $awset[$awardSets[$i]] = $aw;
        }
        return $anyAward ? $awset : null;
    }

    protected function rank($p)
    {
        for ($i = 0; $i < 3; $i++) {
            # Currently not unpacking pointers to next lowest rank.
            $v = unpack('v6', substr($this->data, $p + $i * 20, 20));
            # Don't include zeros from strats that entry or player is not eligible to
            # be ranked it.
            if (!$v[5]) break;
            $rk = [
                'section_rank_low' => $v[0], 'section_rank_high' => $v[1],
                'overall_rank_low' => $v[2], 'overall_rank_high' => $v[3],
                'qual_flag' => $v[4], 'rank' => $v[5]
            ];
            $rkset[] = $rk;
        }
        return $rkset;
    }

    protected function boards($pBoardIdx, $pSection, $isIndy)
    {
        $nCompetitors = $isIndy ? 4 : 2;
        $fmt = 'CC(vs<V)' . $nCompetitors;
        $resultSize = 2 + 8 * $nCompetitors;
        $nBoards = unpack('v', substr($this->data, $pBoardIdx + 4, 4))[1];
        $kupper = 3 * $nCompetitors + 2;

        # Deal with possible EDMOV pair reassignment, first checking if any reassignments
        # have occurred to minimize time spent in the loops below.
        $isHowell = unpack('C', substr($this->data, $pSection + 0x18))[1];
        $reassign = $isHowell ? unpack('C80', substr($this->data, $pSection + 0xdd, 80)) : unpack('C160', substr($this->data, $pSection + 0xdd, 160));
        $anyReassignedPairs = 0;

        # It's not clear if individual reassignments are possible in an individual event.
        # Don't both for such. Individual events are rare and reassignments are rarer.
        if (!$isIndy) {
            foreach ($reassign as $val) {
                if ($val) {
                    $anyReassignedPairs = 1;
                    break;
                }
            }
        }

        for ($i = 0; $i < $nBoards; $i++) {
            $bd = [];
            list($bnum, $nresults, $p) = unpack('vvV', substr($this->data, $pBoardIdx + 0x26 + $i * 8, 8));
            $p += 6;

            for ($j = 0; $j < $nresults; $j++) {
                $v = unpack($fmt, substr($this->data, $p + $j * $resultSize, $resultSize));

                # Skip if board is not in play on this round.
                if ($v[3] == 999) continue;

                for ($k=2; $k<$kupper; $k+=3) {
                    if ($anyReassignedPairs) {
                        if ($isHowell) {
                            if($reassign[$v[$k]-1]) {
                                $v[$k] = $reassign[$v[$k]-1];
                            }
                        }else {
                            # 0 for N-S, 1 for E-W
                            $dr = ($k == 5);
                            if($reassign[4*($v[$k]-1)+$dr]) {
                                $v[$k] = $reassign[4 * ($v[$k] - 1) + $dr];
                            }
                        }
                    }
                    if ($v[$k+1] < 900) {
                        $v[$k+1] *= 10;
                    }else {
                        $v[$k+1] = isset($v[$k+1]) ? $this->getSpecialScore($v[$k+1]) : 'Unknown';
                    }
                    $v[$k+2] /= 100;
                }
                $bd[] = $v;
            }
            $bdset[$bnum] = $bd;
        }
        return $bdset;
    }

    protected function teamMatches($p, $pIndex)
    {
        # Construct mapping from Team Entry ID to Team number.
        list($nteams, $nrounds) = unpack('vv', substr($this->data, $p + 4, 4));
        $tmap = unpack("(vx6)$nteams", substr($this->data, $pIndex + 0x14, 8 * $nteams));
        $pTMT = unpack("V$nteams", substr($this->data, $p + 0x56, 4 * $nteams));

        for ($i = 0; $i < $nteams; $i++) {
            $team = [];
            # Pointer to Team Match Table
            $pTMT = $pTMT[$i];
            # A team might not play all rounds of the event, e.g. when a team gets
            # knocked out of Knockout event.

            $nmatches = unpack('C', substr($this->data, $pTMT + 4, 1))[1];

            for ($j=0; $j<$nmatches; $j++) {
                # Team Match Entry
                $pTME = $pTMT + 0x22 + $j * self::TEAM_MATCH_ENTRY_SIZE;
                $vsTeamID = unpack('v', substr($this->data, $pTME + 2, 2))[1];

                $team[] = [
                    'round' => unpack('C', substr($this->data, $pTME + 1, 1))[1],
                    'vs_team' => unpack('v', substr($this->data, $pTME + 2, 2))[1],
                    'IMPs' => unpack('s<', substr($this->data, $pTME + 8, 2))[1],
                    'VPs' => unpack('v', substr($this->data, $pTME + 0x0a, 2))[1] / 100,
                    'nboards' => unpack('C', substr($this->data, $pTME + 0x0d, 1))[1],
                    'wins' => unpack('v', substr($this->data, $pTME + 0x16, 2))[1] / 100
                ];
            }
            $tmset[$tmap[$i]] = $team;
        }
        return $tmset;
    }
}