if(typeof BackendCommFC === 'undefined') {
	var BackendCommFC = class{
		#plugin;
		static #backend = 'backend.php';
		static #op = "pluginhandler";
		static #response_proc_funcs;
		static {
			this.#response_proc_funcs = {
				notify: (msg) => Notify.info(msg),
				notifyError: (msg) => Notify.error(msg),
				debug: (msg) => console.debug(msg)
			}
		}

		/**
		 *
		 * @param {string} plugin the name of the plugin that the backend request should go to.
		 */
		constructor(plugin) {
			this.#plugin = plugin;
		}

		/**
		 * send a backend request with its argument as body, processes the returned value as JSON and returns it.
		 * Throws on network problems/invalid JSON.
		 *
		 * @param {*} post_body Any type that Request accepts for body
		 * @returns {Promise<any>}
		 */
		static async #backend_request_json(post_body) {
			const req = new Request(this.#backend, {method: "POST", body: post_body});
			console.debug("Prepared backend request: ", req, "with body: ", post_body);

			const response = await fetch(req);
			if(! response.ok) {
				const error = new Error(`HTTP error ${response.status} (${response.statusText}) for ${response.url}.`);
				if(response.status >= 500 && response.status < 600) error.message += " (Check web server log).";
				throw error;
			}

			let answer;
			try {
				answer = await response.json();
			} catch(error) {
				error.message = `${response.url} didn't return JSON (${error.message}).`;
				throw error;
			}

			return answer;
		}

		/**
		 * Process the backend response. Certain keys are used for some common tasks
		 * like GUI feedback (`Notify`) or writing to the console.
		 *
		 * Other keys may be introduced, with the exception of the **proc** key.
		 * Any values that the caller wants to use should go under it, but this is not enforced.
		 *
		 * @param {any} answer
		 * @returns {void}
		 */
		static #process_response(answer) {
			if(! answer instanceof Object) return;  // no numbers, strings, bools, null

			if(answer.hasOwnProperty('error') ) {  // ttrss errors
				Notify.error(answer.error.message);
				console.error(answer);
				return;
			}

			let msg;

			if(msg = answer.errMsg ?? null) {  // our own errors
				Notify.error(msg);
				console.error(answer);
			}

			// see class top for defined fields
			for(const [field, func] of Object.entries(this.#response_proc_funcs)) {
				const msg = answer[field] ?? null;
				if(msg) func(msg);
			}
		}

		/**
		 * evaluates its argument as dojo form node and returns its values if it validates.
		 *
		 * Throws an Error otherwise. Since `validate()` gives GUI feedback,
		 * logging the error can be omitted, only the backend request should not happen.
		 *
		 * @param {*} node A dojo form node
		 * @returns {Object} values of the form as object
		 * @see https://dojotoolkit.org/api/?qs=1.10/dijit/form/Form#getValues
		 * @see https://dojotoolkit.org/api/?qs=1.10/dijit/form/Form#validate
		 * @see https://dojotoolkit.org/reference-guide/1.10/dijit/form/Form.html
		 * @see https://dojotoolkit.org/reference-guide/1.10/dijit/form/ValidationTextBox.html
		 */
		static #eval_dojo_form(node) {
			if(! node.validate()) throw new Error("Dojo Validation failed.");

			const val = node.getValues();
			console.debug("Got dojo values: ", val);

			return val;
		}

		/**
		 *
		 * @param {string} method
		 * @param {Object} params
		 * @param {*} node
		 * @returns {URLSearchParams}
		 */
		#prepare_post_body(method, params, node=null) {
			const params_ = {};
			if(node) {
				const dojo_params = this.constructor.#eval_dojo_form(node);
				Object.assign(params_, dojo_params);
			}
			Object.assign(params_, params);
			params_.op = this.constructor.#op;
			params_.plugin = this.#plugin.toLowerCase();
			if(method) params_.method = method;

			return new URLSearchParams(params_);
		}

		/**
		 * send a request to the backend of ttrss, return the result.
		 *
		 * Handles errors and processes some requested callee notifications, console entries, etc.
		 *
		 * @param {string} method the method name that should be called
		 * @param {Object} params additional paramters
		 * @param {*} node A dojo form node whose values will be turned into parameters for the request
		 * @returns {Promise<any>}
		 */
		async post_notify(method='', params={}, node=null) {
			try {
				const params_ = this.#prepare_post_body(...arguments);
				const answer = await this.constructor.#backend_request_json(params_);
				this.constructor.#process_response(answer);
				return answer;
			} catch(error) {
				Notify.error(error.message + " (check JS console for backtrace)");
				console.error(error);
				return null;
			}
		}
	};
}
