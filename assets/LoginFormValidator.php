<?php
	
	namespace Quellabs\Canvas\Validation;
	
	use Quellabs\CanvasValidation\Contracts\ValidationInterface;
	use Quellabs\CanvasValidation\Rules\Email;
	use Quellabs\CanvasValidation\Rules\NotBlank;
	
	/**
	 * Validator class for login form data
	 */
	class LoginFormValidator implements ValidationInterface {
		
		/**
		 * Define validation rules for login form fields
		 * @return array Array of validation rules keyed by field name
		 */
		public function getRules(): array {
			return [
				// Username field validation
				'username' => [
					new NotBlank(),  // Ensure username is not empty
					new Email(),     // Validate username is a proper email format
				],
				// Password field validation
				'password' => [
					new NotBlank(),  // Ensure password is not empty
				]
			];
		}
	}