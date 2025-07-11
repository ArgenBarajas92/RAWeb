<?php

declare(strict_types=1);

namespace App\Community\Components;

use App\Community\Enums\AwardType;
use App\Models\System;
use App\Platform\Actions\GetAwardTimeTakenAction;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class UserRecentlyPlayed extends Component
{
    public int $recentlyPlayedCount = 0;
    public array $recentlyPlayedEntities = [];
    public array $recentAchievementEntities = [];
    public array $recentAwardedEntities = [];
    public string $targetUsername = '';
    public int $targetUserId = 0;
    public array $userAwards = [];

    public function __construct(
        int $recentlyPlayedCount = 0,
        array $recentlyPlayedEntities = [],
        array $recentAchievementEntities = [],
        array $recentAwardedEntities = [],
        string $targetUsername = '',
        int $targetUserId = 0,
        array $userAwards = [],
    ) {
        $this->recentlyPlayedCount = $recentlyPlayedCount;
        $this->recentlyPlayedEntities = $recentlyPlayedEntities;
        $this->recentAchievementEntities = $recentAchievementEntities;
        $this->recentAwardedEntities = $recentAwardedEntities;
        $this->targetUsername = $targetUsername;
        $this->targetUserId = $targetUserId;
        $this->userAwards = $userAwards;
    }

    public function render(): View
    {
        $processedRecentlyPlayedEntities = $this->processAllRecentlyPlayedEntities(
            $this->recentlyPlayedCount,
            $this->recentlyPlayedEntities,
            $this->recentAchievementEntities,
            $this->recentAwardedEntities,
            $this->userAwards,
        );

        return view('components.user.recently-played.index', [
            'processedRecentlyPlayedEntities' => $processedRecentlyPlayedEntities,
            'recentlyPlayedCount' => $this->recentlyPlayedCount,
            'targetUsername' => $this->targetUsername,
        ]);
    }

    /**
     * Constructs HTML for achievement avatars to render in the recently played
     * list item's collapse box.
     */
    private function buildAchievementAvatar(array $achievementData): string
    {
        $badgeName = $achievementData['BadgeName'];
        $isAwarded = $achievementData['IsAwarded'];
        $isHardcoreUnlock = $achievementData['HardcoreAchieved'];

        $className = 'badgeimglarge';

        if (!$isAwarded) {
            $badgeName .= '_lock';
        } elseif ($isHardcoreUnlock) {
            $className = 'goldimage';
        }

        return achievementAvatar(
            $achievementData,
            label: false,
            icon: $badgeName,
            iconSize: 48,
            iconClass: $className,
        );
    }

    /**
     * Determine the award kind based on the AwardType and AwardDataExtra values.
     */
    private function determineAwardKind(array $userAward): ?string
    {
        switch ($userAward['AwardType']) {
            case AwardType::Mastery:
                return $userAward['AwardDataExtra'] == 1 ? 'mastered' : 'completed';
            case AwardType::GameBeaten:
                return $userAward['AwardDataExtra'] == 1 ? 'beaten-hardcore' : 'beaten-softcore';
            default:
                return null;
        }
    }

    private function extractGameInformation(array $entity): array
    {
        return [
            'GameID' => $entity['GameID'],
            'ConsoleID' => $entity['ConsoleID'],
            'ImageIcon' => $entity['ImageIcon'],
            'Title' => $entity['Title'],
            'MostRecentWonDate' => $entity['LastPlayed'],
        ];
    }

    /**
     * If a user has earned multiple awards on a game, ensure the award with the
     * highest priority/prestige is the one that renders on the page.
     */
    private function isCandidateAwardHigherPriority(?string $existingAwardKind, ?string $candidateAwardKind): bool
    {
        $priorityLevels = [
            'mastered' => 3,
            'completed' => 2,
            'beaten-hardcore' => 1,
            'beaten-softcore' => 0,
        ];

        $existingAwardPriorityLevel = $existingAwardKind ? $priorityLevels[$existingAwardKind] : -1;
        $candidateAwardPriorityLevel = $candidateAwardKind ? $priorityLevels[$candidateAwardKind] : -1;

        // Does the new award have a higher priority level?
        return $candidateAwardPriorityLevel > $existingAwardPriorityLevel;
    }

    private function processAchievements(array $achievementEntities = [], array $awardedEntity = []): array
    {
        $processed = [
            'NumAwarded' => 0,
            'MaxPossible' => 0,
            'FirstWonDate' => null,
            'PctWonHC' => 0,
            'PctWon' => 0,
            'AchievementAvatars' => [],
        ];

        if (!empty($awardedEntity)) {
            $processed['NumAwarded'] = (int) $awardedEntity['NumAchieved'];
            $processed['MaxPossible'] = (int) $awardedEntity['NumPossibleAchievements'];
            $processed['PctWonHC'] = null;
            $processed['PctWon'] = null;

            $numPossibleAchievements = (int) $awardedEntity['NumPossibleAchievements'];
            $numAchieved = (int) $awardedEntity['NumAchieved'];
            $numAchievedHardcore = (int) $awardedEntity['NumAchievedHardcore'];
            if ($numPossibleAchievements > 0) {
                $processed['PctWonHC'] = $numAchievedHardcore / $numPossibleAchievements;
                $processed['PctWon'] = $numAchieved / $numPossibleAchievements;

                $processed['MaxPossibleScore'] = isset($awardedEntity['PossibleScore']) ? (int) $awardedEntity['PossibleScore'] : 0;
                $processed['ScoreEarnedHardcore'] = (int) $awardedEntity['ScoreAchievedHardcore'];
                $processed['ScoreEarnedSoftcore'] = (int) $awardedEntity['ScoreAchieved'];
            }
        }

        $processed['AchievementAvatars'] = collect($achievementEntities)
            ->map(fn ($achievement) => $this->buildAchievementAvatar($achievement))
            ->all();

        $processed['FirstWonDate'] = collect($achievementEntities)
            ->pluck('DateAwarded')
            ->filter(function ($value) {
                return !is_null($value) && $value !== '';
            })
            ->sort()
            ->first();

        return $processed;
    }

    private function processAllRecentlyPlayedEntities(
        int $recentlyPlayedCount = 0,
        array $rawRecentlyPlayedEntities = [],
        array $recentAchievementEntities = [],
        array $recentAwardedEntities = [],
        array $userAwards = [],
    ): array {
        $entities = collect($rawRecentlyPlayedEntities)
            ->take($recentlyPlayedCount)
            ->map(fn ($recentlyPlayedEntity) => $this->processRecentlyPlayedEntity(
                $recentlyPlayedEntity,
                $recentAchievementEntities[$recentlyPlayedEntity['GameID']] ?? [],
                $recentAwardedEntities[$recentlyPlayedEntity['GameID']] ?? [],
                $userAwards,
            ))
            ->all();

        // merge in award time taken
        $awardGames = [];
        foreach ($entities as $entity) {
            $highestAwardKind = $entity['HighestAwardKind'];
            if ($highestAwardKind) {
                if (!array_key_exists($highestAwardKind, $awardGames)) {
                    $awardGames[$highestAwardKind] = [];
                }
                $awardGames[$highestAwardKind][] = $entity['GameID'];
            }
        }
        foreach ($awardGames as $kind => $gameIds) {
            $times = (new GetAwardTimeTakenAction())
                ->execute($this->targetUserId, $gameIds, $kind);

            foreach ($entities as &$entity) {
                if ($entity['HighestAwardKind'] === $kind) {
                    $entity['HighestAwardTimeTaken'] = $times[$entity['GameID']] ?? null;
                }
            }
        }

        // merge in console names
        $consoles = [];
        foreach ($entities as &$entity) {
            if (!in_array($entity['ConsoleID'], $consoles)) {
                $consoles[] = $entity['ConsoleID'];
            }
        }
        $systems = System::whereIn('id', $consoles)->get();
        foreach ($entities as &$entity) {
            $system = $systems->where('id', $entity['ConsoleID'])->first();
            $entity['ConsoleName'] = $system->name;
            $entity['ConsoleNameShort'] = $system->name_short;
        }

        return $entities;
    }

    private function processAwards(array $userAwards = [], int $targetGameId = 0): array
    {
        $highestAwardDate = null;
        $highestAwardKind = null;

        foreach ($userAwards as $userAward) {
            // Process only the awards related to the target game ID.
            if ($userAward['AwardData'] == $targetGameId) {
                $candidateAwardKind = $this->determineAwardKind($userAward);

                // Update the highest award kind if the current award has higher priority.
                if ($this->isCandidateAwardHigherPriority($highestAwardKind, $candidateAwardKind)) {
                    $highestAwardKind = $candidateAwardKind;
                    $highestAwardDate = $userAward['AwardedAt'];
                }
            }
        }

        return [
            'HighestAwardDate' => $highestAwardDate,
            'HighestAwardKind' => $highestAwardKind,
        ];
    }

    private function processRecentlyPlayedEntity(
        array $recentlyPlayedEntity = [],
        array $achievementEntities = [],
        array $awardedEntity = [],
        array $userAwards = []
    ): array {
        $gameInformation = $this->extractGameInformation($recentlyPlayedEntity);
        $achievementsData = $this->processAchievements($achievementEntities, $awardedEntity);
        $awardsData = $this->processAwards($userAwards, (int) $recentlyPlayedEntity['GameID']);

        return array_merge($gameInformation, $achievementsData, $awardsData);
    }
}
