<?php

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Security;

use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Hook\CheckLoginCredentialsHook;
use Chamilo\CoreBundle\Hook\HookFactory;
use Chamilo\CoreBundle\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Component\Security\Guard\PasswordAuthenticatedInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Class LoginFormAuthenticator.
 */
class LoginFormAuthenticator extends AbstractFormLoginAuthenticator implements PasswordAuthenticatedInterface
{
    use TargetPathTrait;

    private const LOGIN_ROUTE = 'login_json';

    //private $router;
    private $passwordEncoder;
    //private $formFactory;
    private $hookFactory;
    private $userRepository;
    //private $csrfTokenManager;
    private $urlGenerator;
    //private $entityManager;
    public $serializer;

    public function __construct(
        //EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator,
        //RouterInterface $router,
        UserPasswordEncoderInterface $passwordEncoder,
        //FormFactoryInterface $formFactory,
        HookFactory $hookFactory,
        UserRepository $userRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        SerializerInterface $serializer
    ) {
        //$this->router = $router;
        $this->passwordEncoder = $passwordEncoder;
        //$this->formFactory = $formFactory;
        $this->hookFactory = $hookFactory;
        $this->userRepository = $userRepository;
        //$this->csrfTokenManager = $csrfTokenManager;
        //$this->entityManager = $entityManager;
        $this->urlGenerator = $urlGenerator;
        $this->serializer = $serializer;
    }

    public function supports(Request $request): bool
    {
        return self::LOGIN_ROUTE === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function getCredentials(Request $request): array
    {
        $data = null;
        $token = null;
        if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
            $data = json_decode($request->getContent(), true);
            $username = $data['username'];
            $password = $data['password'];
        //$token = $data['csrf_token'];
        } else {
            $username = $request->request->get('username');
            $password = $request->request->get('password');
            $token = $request->request->get('csrf_token');
        }

        $credentials = [
            'username' => $username,
            'password' => $password,
            'csrf_token' => $token,
        ];
        $request->getSession()->set(
            Security::LAST_USERNAME,
            $credentials['username']
        );

        return $credentials;
    }

    /**
     * @param array $credentials
     *
     * @return UserInterface|null
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        /*$token = new CsrfToken('authenticate', $credentials['csrf_token']);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new InvalidCsrfTokenException();
        }*/
        /** @var User $user */
        $user = $this->userRepository->findOneBy(['username' => $credentials['username']]);

        if (!$user) {
            // fail authentication with a custom error
            throw new CustomUserMessageAuthenticationException('username could not be found.');
        }

        return $user;
    }

    /**
     * @throws \Exception
     *
     * @return bool
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        if ($this->passwordEncoder->isPasswordValid($user, $credentials['password'])) {
            return true;
        }

        $hook = $this->hookFactory->build(CheckLoginCredentialsHook::class);

        if (empty($hook)) {
            return false;
        }

        $hook->setEventData(['user' => $user, 'credentials' => $credentials]);

        return $hook->notifyLoginCredentials();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function getPassword($credentials): ?string
    {
        return $credentials['password'];
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        /*$request->getSession()->set(Security::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->router->generate('login'));*/

        $data = [
            // you may want to customize or obfuscate the message first
            'message' => strtr($exception->getMessageKey(), $exception->getMessageData()),

            // or to translate this message
            // $this->translator->trans($exception->getMessageKey(), $exception->getMessageData())
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        $data = [
            // you might translate this message
            'message' => 'Authentication Required',
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @param string $providerKey
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        /*if ($targetPath = $this->getTargetPath($request->getSession(), $providerKey)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('home'));*/

        $user = $token->getUser();
        $userClone = clone $user;
        $userClone->setPassword('');
        $data = $this->serializer->serialize($userClone, JsonEncoder::FORMAT);

        return new JsonResponse($data, Response::HTTP_OK, [], true);
    }

    public function getLoginUrl(): RedirectResponse
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    public function supportsRememberMe()
    {
        return false;
    }
}
