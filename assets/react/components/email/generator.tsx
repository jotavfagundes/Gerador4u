import React, {useState} from "react";
import {Domain, TemporaryEmailBox} from "../../types/types";
import copy from "copy-to-clipboard";

interface Props {
  temporaryEmailBox: TemporaryEmailBox|null;
  handleRegenerateEmail: (domain: string|null) => void;
  isPremium: boolean;
  domains: Domain[];
  selectedDomain: string|null;
  onSelectedDomainChange: (domain: string|null) => void;
  errorMessage: string|null;
}

const Generator = ({
  temporaryEmailBox,
  handleRegenerateEmail,
  isPremium,
  domains,
  selectedDomain,
  onSelectedDomainChange,
  errorMessage,
}: Props) => {
  const [copied, setCopied] = useState(false);

  // The domain picker is a premium-only feature; the backend enforces this as well.
  const canChooseDomain = isPremium && domains.length > 0;

  const handleCopy = () => {
    if (temporaryEmailBox === null) {
      return;
    }

    copy(temporaryEmailBox.email);
    setCopied(true);
  }

  const handleRegenerateButtonPress = () => {
    handleRegenerateEmail(canChooseDomain ? selectedDomain : null);
    setCopied(false);
  }

  const handleDomainChange = (event: React.ChangeEvent<HTMLSelectElement>) => {
    const value = event.target.value;
    onSelectedDomainChange(value === '' ? null : value);
  }

  const regenerateButton = (
    <button className="button is-light" onClick={() => handleRegenerateButtonPress()}>
      <span className="icon"><i className="fas fa-sync-alt"></i></span>
      <span>Gerar novo e-mail</span>
    </button>
  );

  return (
    <section className="hero is-dark">
      <div className="hero-body">
        <div className="container">
          <div className="columns is-centered">
            <div className="column is-8">
              <h1 className="title is-5 has-text-centered has-text-white mb-4">
                Seu endereço de e-mail temporário
              </h1>

              <div className="columns is-mobile is-multiline">
                <div className="column is-12-mobile is-9-tablet">
                  <label>
                    <input
                      className="input is-medium has-text-weight-semibold is-size-6-mobile"
                      type="text"
                      value={temporaryEmailBox === null ? 'Carregando…' : temporaryEmailBox.email}
                      readOnly
                    />
                  </label>
                </div>

                <div className="column is-12-mobile is-3-tablet">
                  <button className="button is-primary is-medium is-fullwidth" onClick={() => handleCopy()}>
                    <span className="icon">
                      <i className={copied ? "fas fa-check" : "fas fa-copy"}></i>
                    </span>
                    <span>{copied ? 'Copiado!' : 'Copiar'}</span>
                  </button>
                </div>
              </div>
            </div>
          </div>

          {errorMessage !== null && (
            <div className="columns is-centered">
              <div className="column is-8">
                <div className="notification is-danger is-light has-text-centered">{errorMessage}</div>
              </div>
            </div>
          )}

          <div className="buttons is-centered">
            {canChooseDomain ? (
              <div className="field is-grouped is-grouped-centered">
                <div className="control">
                  <div className="select">
                    <select value={selectedDomain ?? ''} onChange={handleDomainChange} aria-label="Escolher domínio">
                      <option value="">Domínio aleatório</option>
                      {domains.map((domain) => (
                        <option key={domain.domain} value={domain.domain}>
                          @{domain.domain}{domain.premium ? ' (premium)' : ''}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="control">
                  {regenerateButton}
                </div>
              </div>
            ) : (
              <div className="is-inline">
                {regenerateButton}
              </div>
            )}
          </div>

          <div className="columns is-centered">
            <div className="column is-8">
              <p className="has-text-centered has-text-white is-size-6">
                Use um endereço temporário e gratuito do Mail4u para preservar seu e-mail principal e reduzir o spam.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

export default Generator;
