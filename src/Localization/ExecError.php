<?php

namespace Splicewire\Beam\Exec\Localization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

enum ExecError: string implements CodedError
{
    use IsCodedError;

    #[Message(':detail')]
    case ScenarioPreviewInvalid = 'scenario_preview_invalid';

    #[Message(':detail')]
    case ScenarioSaveInvalid = 'scenario_save_invalid';

    #[Message('Saving a scenario needs a board seat (exec.view).')]
    case SaveBoardSeat = 'scenario_save_board_seat';

    #[Message('Deleting a scenario needs a board seat (exec.view).')]
    case DeleteBoardSeat = 'scenario_delete_board_seat';

    #[Message("The scenario ':scenario' cannot be priced: this host has no plan with the slug :plans. Its price list has: :available.")]
    case ScenarioUnpriced = 'scenario_unpriced';
}
